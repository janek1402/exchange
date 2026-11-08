<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Wallet;
use App\Enum\Currency;
use App\Repository\WalletRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
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
}
