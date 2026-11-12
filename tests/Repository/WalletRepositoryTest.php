<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Wallet;
use App\Enum\Currency;
use App\Exception\WalletAlreadyExistsException;
use App\Repository\WalletRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Only checks the values passed to and from a mocked connection. MariaDB sends DECIMAL columns
 * as strings; whether the migration really created them is not visible here.
 */
class WalletRepositoryTest extends TestCase
{
    public function testDecimalColumnsAreReadWithoutGoingThroughFloat(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'id' => '1',
            'user_id' => '2',
            'currency' => 'PLN',
            'balance' => '99999999999.9999',
            'reserved_balance' => '0.1000',
            'is_blocked' => '0',
            'last_activity_at' => null,
            'created_at' => '2026-01-01 00:00:00',
        ]);

        $wallet = new WalletRepository($connection)->findById(1);

        // As a float this balance would come back as 100000000000.
        self::assertSame('99999999999.9999', $wallet->getBalance());
        self::assertSame('0.1000', $wallet->getReservedBalance());
    }

    public function testBalancesAreWrittenAsDecimalStrings(): void
    {
        $wallet = new Wallet(3, 2, Currency::PLN, '0.3', false, null, new DateTimeImmutable(), '0.1');

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('executeQuery')
            ->with(
                self::anything(),
                self::callback(static fn (array $params): bool => '0.3000' === $params['balance'] && '0.1000' === $params['reserved_balance']),
            );

        new WalletRepository($connection)->save($wallet);
    }

    /** A deleted wallet must look like a missing one everywhere: no transfers, deposits or listing. */
    public function testFindByIdSkipsDeletedWallets(): void
    {
        $connection = $this->connectionBuildingRealSql();
        $connection
            ->expects(self::once())
            ->method('fetchAssociative')
            ->with(self::stringContains('deleted_at IS NULL'))
            ->willReturn(false);

        new WalletRepository($connection)->findById(1);
    }

    public function testFindByUserIdSkipsDeletedWallets(): void
    {
        $connection = $this->connectionBuildingRealSql();
        $connection
            ->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(self::stringContains('deleted_at IS NULL'))
            ->willReturn([]);

        new WalletRepository($connection)->findByUserId(1);
    }

    /** Otherwise a deleted wallet would stop the client from opening a new one in that currency. */
    public function testFindByUserIdAndCurrencySkipsDeletedWallets(): void
    {
        $connection = $this->connectionBuildingRealSql();
        $connection
            ->expects(self::once())
            ->method('fetchAssociative')
            ->with(self::stringContains('deleted_at IS NULL'))
            ->willReturn(false);

        new WalletRepository($connection)->findByUserIdAndCurrency(1, Currency::EUR);
    }

    public function testDeletionDateIsWritten(): void
    {
        $wallet = new Wallet(3, 2, Currency::PLN, '0', false, null, new DateTimeImmutable());
        $wallet->markDeleted(new DateTimeImmutable('2026-11-08 12:00:00', new DateTimeZone('UTC')));

        $connection = $this->connectionBuildingRealSql();
        $connection
            ->expects(self::once())
            ->method('executeQuery')
            ->with(
                self::stringContains('deleted_at'),
                self::callback(static fn (array $params): bool => '2026-11-08 12:00:00' === $params['deleted_at']),
            );

        new WalletRepository($connection)->save($wallet);
    }

    public function testDeletionDateIsRead(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'id' => '1',
            'user_id' => '2',
            'currency' => 'PLN',
            'balance' => '0.0000',
            'reserved_balance' => '0.0000',
            'is_blocked' => '0',
            'last_activity_at' => null,
            'created_at' => '2026-01-01 00:00:00',
            'deleted_at' => '2026-11-08 12:00:00',
        ]);

        $wallet = new WalletRepository($connection)->findById(1);

        self::assertTrue($wallet->isDeleted());
    }

    /**
     * Two "create EUR wallet" requests at the same moment both pass the "does it exist?" check;
     * the database index stops the second one. That is a 409 for the client, not a 500.
     */
    public function testSecondActiveWalletInTheSameCurrencyIsReportedAsAlreadyExisting(): void
    {
        $connection = $this->connectionBuildingRealSql();
        $connection->expects(self::once())->method('executeQuery')->willThrowException(
            new UniqueConstraintViolationException($this->driverError('Duplicate entry for key wallet_user_currency_active_unique'), null),
        );

        $wallet = Wallet::create(2, Currency::EUR);

        try {
            new WalletRepository($connection)->save($wallet);
            self::fail('Expected WalletAlreadyExistsException.');
        } catch (WalletAlreadyExistsException $e) {
            self::assertSame('Wallet for user 2 in currency EUR already exists.', $e->getMessage());
        }

        self::assertNull($wallet->getId(), 'The wallet was not saved, so it must not get an ID.');
    }

    /** Any other database error stays an error: hiding it as "already exists" would mislead. */
    public function testOtherDatabaseErrorsAreNotHidden(): void
    {
        $error = new DriverException($this->driverError('Lost connection'), null);

        $connection = $this->connectionBuildingRealSql();
        $connection->expects(self::once())->method('executeQuery')->willThrowException($error);

        $this->expectExceptionObject($error);

        new WalletRepository($connection)->save(Wallet::create(2, Currency::EUR));
    }

    private function driverError(string $message): AbstractException
    {
        return new class($message) extends AbstractException {
        };
    }

    /** A plain mock hands out an empty query builder; this one builds the real MariaDB SQL. */
    private function connectionBuildingRealSql(): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
        $connection->method('createQueryBuilder')->willReturnCallback(static fn (): QueryBuilder => new QueryBuilder($connection));

        return $connection;
    }
}
