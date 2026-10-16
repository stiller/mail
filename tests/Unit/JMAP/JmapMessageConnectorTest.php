<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\JMAP;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Account;
use OCA\Mail\Db\Mailbox;
use OCA\Mail\Db\MailboxMapper;
use OCA\Mail\Db\Message;
use OCA\Mail\Db\MessageMapper;
use OCA\Mail\JMAP\JmapMessageAdapter;
use OCA\Mail\JMAP\JmapMessageConnector;
use OCA\Mail\Service\JMAP\JmapOperationsService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class JmapMessageConnectorTest extends TestCase {
	private JmapOperationsService&MockObject $jmapOperationsService;
	private MessageMapper&MockObject $messageMapper;
	private MailboxMapper&MockObject $mailboxMapper;
	private Account&MockObject $account;
	private Mailbox $mailbox;
	private JmapMessageConnector $connector;

	protected function setUp(): void {
		parent::setUp();

		$this->jmapOperationsService = $this->createMock(JmapOperationsService::class);
		$this->messageMapper = $this->createMock(MessageMapper::class);
		$this->mailboxMapper = $this->createMock(MailboxMapper::class);
		$this->account = $this->createMock(Account::class);
		$this->mailbox = new Mailbox();
		$this->mailbox->setId(7);
		$this->mailbox->setRemoteId('inbox');
		$this->mailbox->setSelectable(true);

		$this->connector = new JmapMessageConnector(
			$this->jmapOperationsService,
			$this->createMock(JmapMessageAdapter::class),
			$this->messageMapper,
			$this->mailboxMapper,
			$this->createMock(IEventDispatcher::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testSyncMailboxFetchesAndWritesInBatches(): void {
		$remoteIds = array_map(static fn (int $i): string => "m$i", range(1, 2500));
		$this->jmapOperationsService->method('entityDelta')
			->with('inbox', '')
			->willReturn(['state' => 's1', 'additions' => $remoteIds, 'modifications' => [], 'deletions' => []]);
		$fetchedBatches = [];
		$this->jmapOperationsService->expects(self::exactly(3))
			->method('entityFetchMessage')
			->willReturnCallback(function (string ...$ids) use (&$fetchedBatches): array {
				$fetchedBatches[] = $ids;
				return $this->remoteMessages(...$ids);
			});
		$lookedUpBatches = [];
		$this->messageMapper->expects(self::exactly(3))
			->method('findByRemoteIds')
			->willReturnCallback(static function (Mailbox $mailbox, array $ids) use (&$lookedUpBatches): array {
				$lookedUpBatches[] = $ids;
				return [];
			});
		$this->messageMapper->method('findHighestUid')->willReturn(null);
		$insertedCounts = [];
		$this->messageMapper->expects(self::exactly(3))
			->method('insertBulk')
			->willReturnCallback(static function (Account $account, Message ...$messages) use (&$insertedCounts): void {
				$insertedCounts[] = count($messages);
			});
		$this->messageMapper->expects(self::never())->method('updateBulk');
		$this->mailboxMapper->expects(self::once())->method('update')->with($this->mailbox);

		$result = $this->connector->syncMailbox($this->account, $this->mailbox, $this->createMock(LoggerInterface::class), 0);

		self::assertSame([1000, 1000, 500], array_map('count', $fetchedBatches));
		self::assertSame($fetchedBatches, $lookedUpBatches);
		self::assertSame($remoteIds, array_merge(...$fetchedBatches));
		self::assertSame([1000, 1000, 500], $insertedCounts);
		self::assertSame(range(1, 2500), $result->new);
		self::assertSame('s1', $result->state);
		self::assertSame('s1', $this->mailbox->getState());
	}

	public function testSyncMailboxUpdatesKnownMessagesAndKeepsTheirUid(): void {
		$this->mailbox->setState('s1');
		$this->jmapOperationsService->method('entityDelta')
			->with('inbox', 's1')
			->willReturn(['state' => 's2', 'additions' => ['new'], 'modifications' => ['known'], 'deletions' => []]);
		$this->jmapOperationsService->method('entityFetchMessage')
			->with('new', 'known')
			->willReturn($this->remoteMessages('new', 'known'));
		$local = new Message();
		$local->setRemoteId('known');
		$local->setUid(3);
		$this->messageMapper->method('findByRemoteIds')
			->with($this->mailbox, ['new', 'known'])
			->willReturn([$local]);
		$this->messageMapper->method('findHighestUid')->willReturn(10);
		$this->messageMapper->expects(self::once())
			->method('insertBulk')
			->with($this->account, self::callback(static fn (Message $message): bool => $message->getRemoteId() === 'new' && $message->getUid() === 11 && $message->getMailboxId() === 7));
		$this->messageMapper->expects(self::once())
			->method('updateBulk')
			->with($this->account, true, self::callback(static fn (Message $message): bool => $message === $local && $message->getSubject() === 'Subject known'));

		$result = $this->connector->syncMailbox($this->account, $this->mailbox, $this->createMock(LoggerInterface::class), 0);

		self::assertSame([11], $result->new);
		self::assertSame([3], $result->modified);
		self::assertSame('s2', $result->state);
	}

	public function testSyncMailboxWithoutChangesFetchesNothing(): void {
		$this->mailbox->setState('s1');
		$this->jmapOperationsService->method('entityDelta')
			->willReturn(['state' => 's2', 'additions' => [], 'modifications' => [], 'deletions' => []]);
		$this->jmapOperationsService->expects(self::never())->method('entityFetchMessage');
		$this->messageMapper->expects(self::never())->method('findByRemoteIds');
		$this->messageMapper->expects(self::never())->method('insertBulk');
		$this->mailboxMapper->expects(self::once())->method('update')->with($this->mailbox);

		$result = $this->connector->syncMailbox($this->account, $this->mailbox, $this->createMock(LoggerInterface::class), 0);

		self::assertSame([], $result->new);
		self::assertSame('s2', $result->state);
	}

	public function testSyncMailboxWithUnchangedStateReturnsEarly(): void {
		$this->mailbox->setState('s1');
		$this->jmapOperationsService->method('entityDelta')
			->willReturn(['state' => 's1', 'additions' => [], 'modifications' => [], 'deletions' => []]);
		$this->jmapOperationsService->expects(self::never())->method('entityFetchMessage');
		$this->mailboxMapper->expects(self::never())->method('update');

		$result = $this->connector->syncMailbox($this->account, $this->mailbox, $this->createMock(LoggerInterface::class), 0);

		self::assertSame('s1', $result->state);
	}

	/**
	 * @return array<string, Message>
	 */
	private function remoteMessages(string ...$ids): array {
		$messages = [];
		foreach ($ids as $id) {
			$message = new Message();
			$message->setRemoteId($id);
			$message->setSubject("Subject $id");
			$messages[$id] = $message;
		}
		return $messages;
	}
}
