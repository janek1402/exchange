<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\ExchangeRateService;
use App\Service\SpreadService;
use App\Service\TransferService;
use App\Tests\Support\ImmediateDatabaseTransaction;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Uses the real rate and spread services, because the threshold depends on actual conversion.
 */
class TransferAntiFraudThresholdTest extends TestCase
{
    /**
     * @return iterable<string, array{Currency, Currency, string, bool}>
     */
    public static function transfers(): iterable
    {
        yield 'target amount under 15000 hides 17221 EUR' => [Currency::PLN, Currency::GBP, '73000', true];
        yield 'target amount under 15000 hides 16278 EUR' => [Currency::PLN, Currency::CHF, '69000', true];
        yield 'over 15000 EUR before spread, under after it' => [Currency::PLN, Currency::EUR, '63600', true];
        yield 'just above the threshold in EUR' => [Currency::EUR, Currency::USD, '15000.01', true];
        yield 'just above the threshold in PLN' => [Currency::PLN, Currency::USD, '63584', true];

        yield 'exactly 15000 EUR is not above the threshold' => [Currency::EUR, Currency::USD, '15000', false];
        yield 'just below the threshold in PLN' => [Currency::PLN, Currency::USD, '63583', false];
        yield 'target amount over 15000 is only 43 EUR' => [Currency::USD, Currency::HUF, '50', false];
        yield 'target amount over 15000 is only 100 EUR' => [Currency::EUR, Currency::JPY, '100', false];
        yield 'target amount over 15000 is only 14000 EUR' => [Currency::EUR, Currency::USD, '14000', false];
    }

    #[DataProvider('transfers')]
    public function testThresholdIsMeasuredInEur(Currency $from, Currency $to, string $amount, bool $requiresCheck): void
    {
        $wallets = $this->createStub(WalletRepositoryInterface::class);
        $wallets
            ->method('findById')
            ->willReturnMap([
                [1, new Wallet(1, 1, $from, '1000000.0', false, null, new DateTimeImmutable())],
                [2, new Wallet(2, 1, $to, '0.0', false, null, new DateTimeImmutable())],
            ]);

        $service = new TransferService(
            $wallets,
            $this->createStub(TransactionRepositoryInterface::class),
            new ExchangeRateService(),
            new SpreadService(),
            new ImmediateDatabaseTransaction(),
        );

        $transaction = $service->transfer(1, 1, 2, $amount);

        self::assertSame($requiresCheck, $transaction->requiresAntiFraudCheck());
        self::assertSame(
            $requiresCheck ? TransactionStatus::FRAUD_REVIEW : TransactionStatus::PENDING,
            $transaction->getStatus(),
        );
    }
}
