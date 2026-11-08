<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\TransactionRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Only checks the query sent to a mocked connection; whether MariaDB answers it as expected
 * is verified on a real database.
 */
class TransactionRepositoryTest extends TestCase
{
    /** Incoming transfers leave no reservation on the wallet, so both directions are checked. */
    public function testOpenTransactionsAreLookedUpInBothDirectionsAndOnlyForOpenStatuses(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('fetchOne')
            ->with(
                self::logicalAnd(
                    self::stringContains('from_wallet_id = :wallet_id'),
                    self::stringContains('to_wallet_id = :wallet_id'),
                    self::stringContains('status IN (:pending, :fraud_review)'),
                ),
                ['wallet_id' => 7, 'pending' => 'pending', 'fraud_review' => 'fraud_review'],
            )
            ->willReturn('1');

        self::assertTrue(new TransactionRepository($connection)->hasOpenTransactions(7));
    }

    public function testNoOpenTransactions(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(false);

        self::assertFalse(new TransactionRepository($connection)->hasOpenTransactions(7));
    }
}
