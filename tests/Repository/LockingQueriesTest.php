<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Transaction;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Repository\CompanyWalletRepository;
use App\Repository\TransactionRepository;
use App\Repository\WalletRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Checks only the queries sent to a mocked connection. Whether MariaDB really waits on these
 * locks cannot be shown without a database.
 */
class LockingQueriesTest extends TestCase
{
    public function testWalletsAreLockedOnePerQueryInAscendingOrderWithoutDuplicates(): void
    {
        $locked = [];

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::exactly(3))
            ->method('fetchAssociative')
            ->willReturnCallback(static function (string $sql, array $params) use (&$locked): false {
                self::assertStringContainsString('FOR UPDATE', $sql);
                self::assertStringContainsString('wallets', $sql);
                $locked[] = $params['id'];

                return false;
            });

        new WalletRepository($connection)->lockForUpdate(7, 2, 7, 5);

        self::assertSame([2, 5, 7], $locked);
    }

    public function testRefreshLocksTransactionRowAndLoadsStoredState(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('fetchAssociative')
            ->with(
                self::logicalAnd(self::stringContains('transactions'), self::stringContains('FOR UPDATE')),
                ['id' => 5],
            )
            ->willReturn(['status' => 'completed', 'anti_fraud_checked_at' => '2026-01-02 03:04:05']);

        $transaction = $this->transaction(5);

        new TransactionRepository($connection)->refreshForUpdate($transaction);

        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
        self::assertSame('2026-01-02 03:04:05', $transaction->getAntiFraudCheckedAt()?->format('Y-m-d H:i:s'));
    }

    public function testRefreshFailsWhenTransactionRowIsGone(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);

        $this->expectException(RuntimeException::class);

        new TransactionRepository($connection)->refreshForUpdate($this->transaction(5));
    }

    public function testRefreshFailsForTransactionThatWasNeverSaved(): void
    {
        $this->expectException(LogicException::class);

        new TransactionRepository($this->createStub(Connection::class))->refreshForUpdate($this->transaction(null));
    }

    /** A separate read and write would let two first spreads in a currency collide. */
    public function testAddingToCompanyWalletIsOneAtomicQuery(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchAssociative');
        $connection
            ->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::logicalAnd(
                    self::stringContains('INSERT INTO company_wallets'),
                    self::stringContains('ON DUPLICATE KEY UPDATE'),
                    self::stringContains('balance = balance + VALUES(balance)'),
                ),
                self::callback(static fn (array $params): bool => 'EUR' === $params['currency'] && '1.2500' === $params['balance']),
            );

        new CompanyWalletRepository($connection)->addToBalance(Currency::EUR, '1.2500');
    }

    private function transaction(?int $id): Transaction
    {
        return new Transaction(
            id: $id,
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: '100.0000',
            toAmount: '25.0000',
            fromCurrency: Currency::PLN,
            toCurrency: Currency::EUR,
            spread: '0.5000',
            exchangeRate: '0.250000',
            status: TransactionStatus::PENDING,
            requiresAntiFraudCheck: false,
            antiFraudCheckedAt: null,
            createdAt: new DateTimeImmutable(),
        );
    }
}
