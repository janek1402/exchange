<?php

declare(strict_types=1);

namespace App\Tests\Dto;

use App\Dto\WalletResponse;
use App\Entity\Wallet;
use App\Enum\Currency;
use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\TestCase;

class WalletResponseTest extends TestCase
{
    public function testJsonSerializeWithoutLastActivityAt(): void
    {
        $wallet = Wallet::create(1, Currency::PLN);

        $data = new WalletResponse($wallet)->jsonSerialize();

        self::assertNull($data['id']);
        self::assertSame('PLN', $data['currency']);
        self::assertSame('0.0000', $data['balance']);
        self::assertFalse($data['isBlocked']);
        self::assertNull($data['lastActivityAt']);
    }

    public function testJsonSerializeWithLastActivityAt(): void
    {
        $lastActivity = new DateTimeImmutable('2024-06-01T12:00:00+00:00');
        $wallet = Wallet::create(1, Currency::EUR);
        $wallet->setLastActivityAt($lastActivity);

        $data = new WalletResponse($wallet)->jsonSerialize();

        self::assertSame($lastActivity->format(DateTimeInterface::ATOM), $data['lastActivityAt']);
    }

    public function testJsonSerializeIncludesReservedBalance(): void
    {
        $wallet = Wallet::create(1, Currency::PLN);
        $wallet->reserve('25.0');

        $data = new WalletResponse($wallet)->jsonSerialize();

        self::assertSame('25.0000', $data['reservedBalance']);
    }

    public function testBalancesAreSentAsExactDecimalStrings(): void
    {
        $wallet = new Wallet(1, 1, Currency::PLN, '0.3000', false, null, new DateTimeImmutable(), '0.1000');

        $json = json_encode(new WalletResponse($wallet), JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"balance":"0.3000"', $json);
        self::assertStringContainsString('"reservedBalance":"0.1000"', $json);
    }

    public function testJsonSerializeWithBlockedWallet(): void
    {
        $wallet = Wallet::create(1, Currency::USD);
        $wallet->setIsBlocked(true);

        $data = new WalletResponse($wallet)->jsonSerialize();

        self::assertTrue($data['isBlocked']);
    }
}
