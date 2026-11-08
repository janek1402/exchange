<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Wallet;
use App\Enum\Currency;
use DateTimeImmutable;
use Generator;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WalletTest extends TestCase
{
    public function testGetters(): void
    {
        $wallet = Wallet::create(userId: 5, currency: Currency::EUR);

        $this->assertNull($wallet->getId());
        $this->assertSame(5, $wallet->getUserId());
        $this->assertSame(Currency::EUR, $wallet->getCurrency());
        $this->assertSame('0.0000', $wallet->getBalance());
        $this->assertFalse($wallet->isBlocked());
        $this->assertNull($wallet->getLastActivityAt());
    }

    public function testSetBalance(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::PLN);
        $wallet->setBalance('150.50');

        $this->assertSame('150.5000', $wallet->getBalance());
    }

    public function testNewWalletHasNoReservedFunds(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::PLN);

        $this->assertSame('0.0000', $wallet->getReservedBalance());
    }

    public function testReserveAndReleaseDoNotChangeBalance(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::PLN);
        $wallet->setBalance('100.0');

        $wallet->reserve('40.0');
        $this->assertSame('40.0000', $wallet->getReservedBalance());

        $wallet->release('15.0');
        $this->assertSame('25.0000', $wallet->getReservedBalance());
        $this->assertSame('100.0000', $wallet->getBalance());
    }

    public function testReleaseThrowsWhenAmountExceedsReservation(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::PLN);
        $wallet->reserve('10.0');

        try {
            $wallet->release('25.0');
            $this->fail('Expected LogicException.');
        } catch (LogicException) {
        }

        $this->assertSame('10.0000', $wallet->getReservedBalance());
    }

    public function testReleaseToleratesFloatNoiseInSumOfReservations(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::PLN);
        $wallet->reserve('0.7');
        $wallet->reserve('0.1');

        $wallet->release('0.7');
        $wallet->release('0.1');

        $this->assertSame('0.0000', $wallet->getReservedBalance());
    }

    #[DataProvider('nonPositiveAmountProvider')]
    public function testReserveRejectsNonPositiveAmount(string $amount): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::PLN);

        $this->expectException(InvalidArgumentException::class);

        $wallet->reserve($amount);
    }

    #[DataProvider('nonPositiveAmountProvider')]
    public function testReleaseRejectsNonPositiveAmount(string $amount): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::PLN);
        $wallet->reserve('10.0');

        $this->expectException(InvalidArgumentException::class);

        $wallet->release($amount);
    }

    public static function nonPositiveAmountProvider(): Generator
    {
        yield 'zero' => ['0.0'];
        yield 'negative' => ['-5.0'];
        yield 'rounds to zero at ledger precision' => ['0.00001'];
    }

    public function testBalanceFromDatabaseIsKeptExactly(): void
    {
        $wallet = new Wallet(1, 1, Currency::PLN, '0.3000', false, null, new DateTimeImmutable(), '0.1000');

        $this->assertSame('0.3000', $wallet->getBalance());
        $this->assertSame('0.1000', $wallet->getReservedBalance());
        $this->assertSame('0.2000', $wallet->getAvailableBalance());
    }

    public function testAvailableBalanceIsExactForManySmallReservations(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::PLN);
        $wallet->setBalance('1');

        for ($i = 0; $i < 10; ++$i) {
            $wallet->reserve('0.1');
        }

        $this->assertSame('1.0000', $wallet->getReservedBalance());
        $this->assertSame('0.0000', $wallet->getAvailableBalance());
    }

    public function testSetBalanceRejectsNegativeBalance(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::PLN);
        $wallet->setBalance('10');

        try {
            $wallet->setBalance('-0.0001');
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame('10.0000', $wallet->getBalance());
    }

    public function testSetBalanceNeverRoundsSilently(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::PLN);

        $this->expectException(InvalidArgumentException::class);

        $wallet->setBalance('0.00001');
    }

    public function testSetIsBlocked(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::PLN);
        $wallet->setIsBlocked(true);

        $this->assertTrue($wallet->isBlocked());
    }

    public function testSetLastActivityAt(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::PLN);
        $date = new DateTimeImmutable('2024-06-01 10:00:00');
        $wallet->setLastActivityAt($date);

        $this->assertSame($date, $wallet->getLastActivityAt());
    }

    public function testSetLastActivityAtWithNull(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::PLN);
        $wallet->setLastActivityAt(new DateTimeImmutable('2024-06-01 10:00:00'));
        $wallet->setLastActivityAt(null);

        $this->assertNull($wallet->getLastActivityAt());
    }
}
