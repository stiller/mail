<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Service\JMAP;

use ChristophWurst\Nextcloud\Testing\TestCase;
use JmapClient\Client;
use JmapClient\Requests\Mail\MailParameters as MailParametersRequest;
use JmapClient\Requests\Request;
use JmapClient\Responses\Mail\MailParameters as MailParametersResponse;
use JmapClient\Responses\ResponseBundle;
use JmapClient\Responses\ResponseException;
use JmapClient\Session\Session;
use OCA\Mail\Db\Message;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\JMAP\Exception\JmapException;
use OCA\Mail\JMAP\JmapMailboxAdapter;
use OCA\Mail\JMAP\JmapMessageAdapter;
use OCA\Mail\Protocol\ProtocolFactory;
use OCA\Mail\Service\JMAP\JmapOperationsService;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;

class JmapOperationsServiceTest extends TestCase {

	private ProtocolFactory|MockObject $protocolFactory;
	private JmapMailboxAdapter|MockObject $jmapMailboxAdapter;
	private JmapMessageAdapter|MockObject $jmapMessageAdapter;
	private LoggerInterface|MockObject $logger;
	private Client|MockObject $dataStore;
	private JmapOperationsService $service;
	/** @var list<list<array>> */
	private array $requests = [];

	protected function setUp(): void {
		parent::setUp();

		$this->protocolFactory = $this->createMock(ProtocolFactory::class);
		$this->jmapMailboxAdapter = $this->createMock(JmapMailboxAdapter::class);
		$this->jmapMessageAdapter = $this->createMock(JmapMessageAdapter::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->dataStore = $this->createMock(Client::class);

		$this->service = new JmapOperationsService(
			$this->protocolFactory,
			$this->jmapMailboxAdapter,
			$this->jmapMessageAdapter,
			$this->logger,
		);

		// Bypass connect()'s session handshake and inject an already-connected data store,
		// since attachmentUpload() only needs the data store and account id to be set.
		$dataStoreProperty = new ReflectionProperty(JmapOperationsService::class, 'dataStore');
		$dataStoreProperty->setAccessible(true);
		$dataStoreProperty->setValue($this->service, $this->dataStore);

		$dataAccountProperty = new ReflectionProperty(JmapOperationsService::class, 'dataAccount');
		$dataAccountProperty->setAccessible(true);
		$dataAccountProperty->setValue($this->service, 'account1');
	}

	public function testAttachmentUpload(): void {
		$content = 'hello world';

		$this->dataStore->expects(self::once())
			->method('upload')
			->with('account1', 'text/plain', $content)
			->willReturn(json_encode(['accountId' => 'account1', 'blobId' => 'blob123', 'type' => 'text/plain', 'size' => strlen($content)]));

		$blobId = $this->service->attachmentUpload('text/plain', $content);

		$this->assertEquals('blob123', $blobId);
	}

	public function testAttachmentUploadMissingBlobIdThrows(): void {
		$this->dataStore->expects(self::once())
			->method('upload')
			->willReturn(json_encode(['accountId' => 'account1']));

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Blob upload did not return a blob id');

		$this->service->attachmentUpload('text/plain', 'hello world');
	}

	/**
	 * Finds the sub-part carrying a blobId in a bound bodyStructure (attachments are
	 * appended as bodyStructure sibling parts, not via the separate "attachments"
	 * property, since a server rejects a create that sets both at once).
	 */
	private function findAttachmentPart(object $bodyStructure): ?object {
		foreach ($bodyStructure->subParts ?? [] as $subPart) {
			if (isset($subPart->blobId)) {
				return $subPart;
			}
		}
		return null;
	}

	public function testEntitySaveWithAttachmentsUploadsAndWiresBlobId(): void {
		$attachments = [
			[
				'content' => 'binary-jpeg-bytes',
				'type' => 'image/jpeg',
				'name' => 'photo.jpg',
				'disposition' => 'attachment',
				'contentId' => null,
			],
		];

		$this->dataStore->expects(self::once())
			->method('upload')
			->with('account1', 'image/jpeg', 'binary-jpeg-bytes')
			->willReturn(json_encode(['accountId' => 'account1', 'blobId' => 'real-uploaded-blob']));

		$captured = [];
		$this->dataStore->expects(self::once())
			->method('perform')
			->willReturnCallback(function (array $requests) use (&$captured) {
				$captured = $requests;
				$mailSetWire = $requests[0]->jsonSerialize();
				$emailId = array_key_first($mailSetWire[1]['create']);
				return new ResponseBundle([
					'methodResponses' => [
						['Email/set', ['created' => [$emailId => ['id' => 'remote-email-1']]], 'c0'],
					],
				]);
			});

		// Mimics JmapTransmissionConnector::buildEmailParams(), which always builds an
		// explicit multipart/mixed bodyStructure before entitySave() wires attachments in.
		$email = new MailParametersRequest();
		$body = $email->bodyPartStructure();
		$body->type('multipart/mixed');
		$body->addPart()->id('text-plain')->type('text/plain')->charset('utf-8');
		$email->bodyPartValue('text-plain', 'body');

		$remoteId = $this->service->entitySave($email, $attachments);

		$this->assertEquals('remote-email-1', $remoteId);
		$this->assertCount(1, $captured);

		$mailSetWire = $captured[0]->jsonSerialize();
		$emailId = array_key_first($mailSetWire[1]['create']);
		$emailCreateObj = $mailSetWire[1]['create'][$emailId];
		$this->assertFalse(isset($emailCreateObj->attachments), 'must not set the separate "attachments" property alongside bodyStructure');
		$attachmentPart = $this->findAttachmentPart($emailCreateObj->bodyStructure);
		$this->assertNotNull($attachmentPart);
		$this->assertEquals('real-uploaded-blob', $attachmentPart->blobId);
		$this->assertEquals('photo.jpg', $attachmentPart->name);
		$this->assertFalse(isset($attachmentPart->partId), 'must not set partId alongside blobId');
	}

	public function testEntitySaveWithoutDispositionOmitsNameAndDisposition(): void {
		$attachments = [
			[
				'content' => 'BEGIN:VCALENDAR',
				'type' => 'text/calendar; method=REQUEST',
				'name' => '',
				'disposition' => null,
				'contentId' => null,
			],
		];

		$this->dataStore->method('upload')
			->willReturn(json_encode(['accountId' => 'account1', 'blobId' => 'calendar-blob']));
		$captured = [];
		$this->dataStore->expects(self::once())
			->method('perform')
			->willReturnCallback(function (array $requests) use (&$captured) {
				$captured = $requests;
				$emailId = array_key_first($requests[0]->jsonSerialize()[1]['create']);
				return new ResponseBundle([
					'methodResponses' => [
						['Email/set', ['created' => [$emailId => ['id' => 'remote-email-2']]], 'c0'],
					],
				]);
			});

		$email = new MailParametersRequest();
		$email->bodyPartStructure()->type('multipart/mixed');

		$this->service->entitySave($email, $attachments);

		$mailSetWire = $captured[0]->jsonSerialize();
		$emailCreateObj = $mailSetWire[1]['create'][array_key_first($mailSetWire[1]['create'])];
		$attachmentPart = $this->findAttachmentPart($emailCreateObj->bodyStructure);
		$this->assertNotNull($attachmentPart);
		$this->assertFalse(isset($attachmentPart->name));
		$this->assertFalse(isset($attachmentPart->disposition));
	}

	public function testEntitySaveWithoutAttachmentsDoesNotTouchUpload(): void {
		$this->dataStore->expects(self::never())->method('upload');
		$this->dataStore->expects(self::once())
			->method('perform')
			->willReturnCallback(function (array $requests) {
				$mailSetWire = $requests[0]->jsonSerialize();
				$emailId = array_key_first($mailSetWire[1]['create']);
				return new ResponseBundle([
					'methodResponses' => [
						['Email/set', ['created' => [$emailId => ['id' => 'remote-email-3']]], 'c0'],
					],
				]);
			});

		$email = new MailParametersRequest();
		$remoteId = $this->service->entitySave($email, []);

		$this->assertEquals('remote-email-3', $remoteId);
	}

	public function testEntitySaveThrowsWhenAttachmentUploadFails(): void {
		$attachments = [
			[
				'content' => 'binary-jpeg-bytes',
				'type' => 'image/jpeg',
				'name' => 'photo.jpg',
				'disposition' => 'attachment',
				'contentId' => null,
			],
		];

		$this->dataStore->expects(self::once())
			->method('upload')
			->willReturn(json_encode(['accountId' => 'account1']));
		$this->dataStore->expects(self::never())->method('perform');

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Blob upload did not return a blob id');

		$this->service->entitySave(new MailParametersRequest(), $attachments);
	}

	private function sendEmail(): void {
		$email = new MailParametersRequest();
		$email->subject('Test');
		$this->service->entitySend('identity-1', $email, 'drafts-remote', 'sent-remote', 'bob@example.com', ['alice@example.com']);
	}

	public function testEntitySend(): void {
		$this->dataStore->expects(self::once())
			->method('perform')
			->willReturn(new ResponseBundle([
				'methodResponses' => [
					['Email/set', ['created' => ['1' => ['id' => 'staged-1']]], 'c0'],
					['EmailSubmission/set', ['created' => ['2' => ['id' => 'submission-1']]], 'c1'],
				],
			]));

		$this->sendEmail();
	}

	public function testEntitySendWithoutRecipients(): void {
		$this->dataStore->expects(self::never())->method('perform');

		try {
			$this->service->entitySend('identity-1', new MailParametersRequest(), 'drafts-remote', 'sent-remote', 'bob@example.com', []);
			$this->fail('Expected JmapException');
		} catch (JmapException $e) {
			$this->assertSame(ResponseException::INVALID_ARGUMENTS, $e->getType());
			$this->assertFalse($e->isOutcomeUncertain());
		}
	}

	public function testEntitySendRequestFailureIsUncertain(): void {
		$this->dataStore->expects(self::once())
			->method('perform')
			->willThrowException(new RuntimeException('connection reset'));

		try {
			$this->sendEmail();
			$this->fail('Expected JmapException');
		} catch (JmapException $e) {
			$this->assertTrue($e->isOutcomeUncertain());
		}
	}

	public function testEntitySendSaveFailure(): void {
		$this->dataStore->expects(self::once())
			->method('perform')
			->willReturn(new ResponseBundle([
				'methodResponses' => [
					['Email/set', ['notCreated' => ['1' => ['type' => 'overQuota']]], 'c0'],
					['error', ['type' => 'invalidResultReference'], 'c1'],
				],
			]));

		try {
			$this->sendEmail();
			$this->fail('Expected JmapException');
		} catch (JmapException $e) {
			$this->assertSame('overQuota', $e->getType());
			$this->assertFalse($e->isOutcomeUncertain());
		}
	}

	public function testEntitySendSubmissionFailureDiscardsStagedEmail(): void {
		$requests = [];
		$this->dataStore->expects(self::exactly(2))
			->method('perform')
			->willReturnCallback(function (array $commands) use (&$requests) {
				$requests[] = $commands;
				if (count($requests) === 1) {
					return new ResponseBundle([
						'methodResponses' => [
							['Email/set', ['created' => ['1' => ['id' => 'staged-1']]], 'c0'],
							['EmailSubmission/set', ['notCreated' => ['2' => ['type' => 'forbiddenToSend']]], 'c1'],
						],
					]);
				}
				return new ResponseBundle([
					'methodResponses' => [
						['Email/set', ['destroyed' => ['staged-1']], 'c0'],
					],
				]);
			});
		$this->logger->expects(self::never())->method('warning');

		try {
			$this->sendEmail();
			$this->fail('Expected JmapException');
		} catch (JmapException $e) {
			$this->assertSame('forbiddenToSend', $e->getType());
			$this->assertFalse($e->isOutcomeUncertain());
		}
		$this->assertSame(['staged-1'], $requests[1][0]->jsonSerialize()[1]['destroy']);
	}

	public function testEntitySendSubmissionFailureLogsWhenDiscardFails(): void {
		$this->dataStore->expects(self::exactly(2))
			->method('perform')
			->willReturnOnConsecutiveCalls(
				new ResponseBundle([
					'methodResponses' => [
						['Email/set', ['created' => ['1' => ['id' => 'staged-1']]], 'c0'],
						['error', ['type' => ResponseException::SERVER_UNAVAILABLE, 'description' => 'try later'], 'c1'],
					],
				]),
				$this->throwException(new RuntimeException('connection reset')),
			);
		$this->logger->expects(self::once())->method('warning');

		try {
			$this->sendEmail();
			$this->fail('Expected JmapException');
		} catch (JmapException $e) {
			$this->assertSame(ResponseException::SERVER_UNAVAILABLE, $e->getType());
		}
	}

	public function testEntityDeltaWithoutStatePagesThroughTheServerQueryLimit(): void {
		$ids = $this->remoteIds(12000);
		$this->serve($ids, 5000, 500);

		$delta = $this->service->entityDelta('inbox', '');

		self::assertSame('state-1', $delta['state']);
		self::assertSame($ids, $delta['additions']);
		self::assertSame([], $delta['modifications']);
		self::assertSame([], $delta['deletions']);
		$queries = $this->requestsOf('Email/query');
		self::assertSame([0, 5000, 10000, 12000], array_column($queries, 'position'));
		self::assertSame(['inMailbox' => 'inbox'], (array)$queries[0]['filter']);
		self::assertSame([['property' => 'receivedAt', 'isAscending' => false]], array_map(static fn ($sort) => (array)$sort, $queries[0]['sort']));
		$stateRequests = $this->requestsOf('Email/get');
		self::assertCount(1, $stateRequests);
		self::assertSame([], $stateRequests[0]['ids']);
	}

	public function testEntityDeltaWithoutStateReturnsStateOfEmptyMailbox(): void {
		$this->serve([], 5000, 500);

		$delta = $this->service->entityDelta('inbox', '');

		self::assertSame('state-1', $delta['state']);
		self::assertSame([], $delta['additions']);
		self::assertCount(1, $this->requestsOf('Email/query'));
	}

	public function testEntityDeltaWithoutStateStopsWhenServerIgnoresPosition(): void {
		$this->session(500);
		$this->dataStore->method('perform')->willReturnCallback(function (array $commands): ResponseBundle {
			$this->record($commands);
			$responses = [];
			foreach ($commands as $command) {
				[$operation, , $identifier] = $command->jsonSerialize();
				$responses[] = $operation === 'Email/query'
					? ['Email/query', ['accountId' => 'account1', 'queryState' => 'q', 'ids' => ['a', 'b'], 'position' => 0], $identifier]
					: ['Email/get', ['accountId' => 'account1', 'state' => 'state-1', 'list' => [], 'notFound' => []], $identifier];
			}
			return new ResponseBundle(['methodResponses' => $responses]);
		});

		$delta = $this->service->entityDelta('inbox', '');

		self::assertSame(['a', 'b'], $delta['additions']);
		self::assertCount(2, $this->requestsOf('Email/query'));
	}

	public function testEntityDeltaWithoutStateThrowsOnServerError(): void {
		$this->session(500);
		$this->dataStore->method('perform')->willReturnCallback(static function (array $commands): ResponseBundle {
			$responses = [];
			foreach ($commands as $command) {
				[, , $identifier] = $command->jsonSerialize();
				$responses[] = ['error', ['type' => 'serverFail', 'description' => 'boom'], $identifier];
			}
			return new ResponseBundle(['methodResponses' => $responses]);
		});

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('serverFail: boom');

		$this->service->entityDelta('inbox', '');
	}

	public function testEntityFetchMessageChunksToTheServerLimitWithoutBodies(): void {
		$ids = $this->remoteIds(450);
		$this->serve($ids, 5000, 200);

		$messages = $this->service->entityFetchMessage(...$ids);

		self::assertSame($ids, array_keys($messages));
		self::assertSame($ids, array_map(static fn (Message $message): ?string => $message->getRemoteId(), array_values($messages)));
		$gets = $this->requestsOf('Email/get');
		self::assertSame([200, 200, 50], array_map(static fn (array $get): int => count($get['ids']), $gets));
		self::assertNotContains('bodyValues', $gets[0]['properties']);
		self::assertContains('preview', $gets[0]['properties']);
		self::assertContains('hasAttachment', $gets[0]['properties']);
		self::assertArrayNotHasKey('fetchAllBodyValues', $gets[0]);
	}

	public static function chunkSizeProvider(): array {
		return [
			'server limit above the cap' => [10000, [500, 500, 1]],
			'server limit missing' => [null, [500, 500, 1]],
			'server limit invalid' => [0, [500, 500, 1]],
		];
	}

	/**
	 * @dataProvider chunkSizeProvider
	 */
	public function testEntityFetchMessageCapsTheChunkSize(?int $maxObjectsInGet, array $expectedChunks): void {
		$ids = $this->remoteIds(1001);
		$this->serve($ids, 5000, $maxObjectsInGet);

		$messages = $this->service->entityFetchMessage(...$ids);

		self::assertCount(1001, $messages);
		self::assertSame($expectedChunks, array_map(static fn (array $get): int => count($get['ids']), $this->requestsOf('Email/get')));
	}

	public function testEntityFetchMessageThrowsOnServerError(): void {
		$this->serve($this->remoteIds(10), 5000, 500, 'requestTooLarge');

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('requestTooLarge');

		$this->service->entityFetchMessage(...$this->remoteIds(10));
	}

	public function testEntityListThrowsOnServerError(): void {
		$this->serve($this->remoteIds(600), 5000, 500, 'requestTooLarge');

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('requestTooLarge');

		$this->service->entityList('inbox', [], [], null, 'basic');
	}

	/**
	 * @return list<string>
	 */
	private function remoteIds(int $count): array {
		return array_map(static fn (int $i): string => "m$i", range(1, $count));
	}

	private function session(?int $maxObjectsInGet): void {
		$core = $maxObjectsInGet === null ? [] : ['maxObjectsInGet' => $maxObjectsInGet];
		$this->dataStore->method('sessionData')->willReturn(new Session(['capabilities' => ['urn:ietf:params:jmap:core' => $core]]));
		$this->jmapMessageAdapter->method('convertToDatabaseMessage')
			->willReturnCallback(static function (MailParametersResponse $source): Message {
				$message = new Message();
				$message->setRemoteId($source->id());
				return $message;
			});
	}

	/**
	 * Fake server: answers Email/query with at most $queryLimit ids per page and
	 * rejects an Email/get for more than $maxObjectsInGet ids, like Stalwart.
	 *
	 * @param list<string> $mailboxIds
	 */
	private function serve(array $mailboxIds, int $queryLimit, ?int $maxObjectsInGet, ?string $getError = null): void {
		$this->session($maxObjectsInGet);
		$serverLimit = $maxObjectsInGet !== null && $maxObjectsInGet > 0 ? $maxObjectsInGet : 500;
		$this->dataStore->method('perform')->willReturnCallback(function (array $commands) use ($mailboxIds, $queryLimit, $serverLimit, $getError): ResponseBundle {
			$this->record($commands);
			$results = [];
			$responses = [];
			foreach ($commands as $command) {
				[$operation, $arguments, $identifier] = $command->jsonSerialize();
				if ($operation === 'Email/query') {
					$position = $arguments['position'] ?? 0;
					$limit = min($arguments['limit'] ?? PHP_INT_MAX, $queryLimit);
					$results[$identifier] = array_slice($mailboxIds, $position, $limit);
					$responses[] = ['Email/query', ['accountId' => 'account1', 'queryState' => 'q', 'ids' => $results[$identifier], 'position' => $position, 'limit' => $queryLimit], $identifier];
					continue;
				}
				$ids = isset($arguments['#ids']) ? $results[$arguments['#ids']->resultOf] : $arguments['ids'];
				if ($getError !== null || count($ids) > $serverLimit) {
					$responses[] = ['error', ['type' => $getError ?? 'requestTooLarge', 'description' => 'too many ids'], $identifier];
					continue;
				}
				$responses[] = ['Email/get', ['accountId' => 'account1', 'state' => 'state-1', 'list' => array_map(static fn (string $id): array => ['id' => $id], $ids), 'notFound' => []], $identifier];
			}
			return new ResponseBundle(['methodResponses' => $responses]);
		});
	}

	/**
	 * @param Request[] $commands
	 */
	private function record(array $commands): void {
		$this->requests[] = array_map(static fn (Request $command): array => $command->jsonSerialize(), $commands);
	}

	/**
	 * @return list<array>
	 */
	private function requestsOf(string $operation): array {
		$arguments = [];
		foreach ($this->requests as $bundle) {
			foreach ($bundle as [$name, $command]) {
				if ($name === $operation) {
					$arguments[] = $command;
				}
			}
		}
		return $arguments;
	}
}
