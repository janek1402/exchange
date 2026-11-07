<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\InsufficientFundsException;
use App\Exception\InvalidTransactionStateException;
use App\Service\ExchangeRateService;
use App\Service\SpreadService;
use App\Service\TransactionProcessorService;
use App\Service\TransferService;
use App\Tests\Support\InMemoryCompanyWalletRepository;
use App\Tests\Support\InMemoryDatabase;
use App\Tests\Support\InMemoryWalletRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real services together on an in-memory database that hands out a fresh copy
 * on every read, like the real one, so unsaved changes are not visible.
 */
class TransferLifecycleTest extends TestCase
{
    private const int FROM_WALLET_ID = 1;
    private const int TO_WALLET_ID = 2;

    private InMemoryWalletRepository $wallets;
    private InMemoryCompanyWalletRepository $companyWallets;
    private TransferService $transferService;
    private TransactionProcessorService $processor;

    protected function setUp(): void
    {
        $database = new InMemoryDatabase();
        $this->wallets = $database->wallets;
        $this->companyWallets = $database->companyWallets;

        $this->wallets->rows[self::FROM_WALLET_ID] = new Wallet(self::FROM_WALLET_ID, 1, Currency::PLN, 1000.0, false, null, new DateTimeImmutable());
        $this->wallets->rows[self::TO_WALLET_ID] = new Wallet(self::TO_WALLET_ID, 1, Currency::EUR, 0.0, false, null, new DateTimeImmutable());

        $this->transferService = new TransferService($this->wallets, $database->transactions, new ExchangeRateService(), new SpreadService(), $database);
        $this->processor = new TransactionProcessorService($this->wallets, $database->transactions, $this->companyWallets, $database);
    }

    public function testCreatingTransferBooksNoSpread(): void
    {
        $this->transfer('100');

        self::assertSame([], $this->companyWallets->booked);
    }

    public function testCompleteBooksSpreadOfTheTransactionInTargetCurrency(): void
    {
        $transaction = $this->transfer('100');

        $this->processor->complete($transaction);

        self::assertSame([[Currency::EUR, $transaction->getSpread()]], $this->companyWallets->booked);
    }

    public function testClientPayoutAndCompanySpreadAddUpToConvertedAmount(): void
    {
        $transaction = $this->transfer('100');

        $this->processor->complete($transaction);

        $convertedAmount = 100 * new ExchangeRateService()->getExchangeRateBetween(Currency::PLN, Currency::EUR);

        self::assertGreaterThan(0.0, (float) $this->companyWallets->booked[0][1]);
        self::assertEqualsWithDelta(
            $convertedAmount,
            $this->to()->getBalance() + (float) $this->companyWallets->booked[0][1],
            0.0001,
        );
    }

    public function testEachCompletedTransferBooksItsOwnSpread(): void
    {
        $first = $this->transfer('100');
        $second = $this->transfer('200');

        $this->processor->complete($first);
        $this->processor->complete($second);

        self::assertSame(
            [[Currency::EUR, $first->getSpread()], [Currency::EUR, $second->getSpread()]],
            $this->companyWallets->booked,
        );
    }

    public function testRejectBooksNoSpread(): void
    {
        $this->processor->reject($this->transfer('100'));

        self::assertSame([], $this->companyWallets->booked);
    }

    public function testFraudReviewBooksSpreadOnlyAfterApproval(): void
    {
        $this->wallets->rows[self::FROM_WALLET_ID]->setBalance(100000.0);

        $transaction = $this->transfer('100000');
        self::assertSame(TransactionStatus::FRAUD_REVIEW, $transaction->getStatus());
        self::assertSame([], $this->companyWallets->booked);

        $this->processor->complete($transaction);

        self::assertSame([[Currency::EUR, $transaction->getSpread()]], $this->companyWallets->booked);
    }

    public function testCompletingTwiceBooksSpreadOnce(): void
    {
        $transaction = $this->transfer('100');
        $this->processor->complete($transaction);

        try {
            $this->processor->complete($transaction);
            self::fail('Expected InvalidTransactionStateException.');
        } catch (InvalidTransactionStateException) {
        }

        self::assertCount(1, $this->companyWallets->booked);
    }

    public function testCompleteBooksNoSpreadWhenBalanceIsTooLow(): void
    {
        $transaction = $this->transfer('100');
        $this->wallets->rows[self::FROM_WALLET_ID]->setBalance(50.0);

        $this->processor->complete($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertSame([], $this->companyWallets->booked);
    }

    public function testCompleteBooksNoSpreadWhenTargetWalletIsMissing(): void
    {
        $transaction = $this->transfer('100');
        unset($this->wallets->rows[self::TO_WALLET_ID]);

        $this->processor->complete($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertSame([], $this->companyWallets->booked);
    }

    public function testTransferOnlyReservesFunds(): void
    {
        $this->transfer('100');

        self::assertSame(1000.0, $this->from()->getBalance());
        self::assertSame(100.0, $this->from()->getReservedBalance());
        self::assertSame(0.0, $this->to()->getBalance());
    }

    public function testCompleteMovesFundsExactlyOnce(): void
    {
        $this->processor->complete($this->transfer('100'));

        self::assertSame(900.0, $this->from()->getBalance());
        self::assertSame(0.0, $this->from()->getReservedBalance());
        self::assertSame(23.431, $this->to()->getBalance());
    }

    public function testRejectReturnsFundsToSender(): void
    {
        $this->processor->reject($this->transfer('100'));

        self::assertSame(1000.0, $this->from()->getBalance());
        self::assertSame(0.0, $this->from()->getReservedBalance());
        self::assertSame(0.0, $this->to()->getBalance());
    }

    public function testRecipientGetsNothingBeforeFraudReviewApproval(): void
    {
        $this->wallets->rows[self::FROM_WALLET_ID]->setBalance(100000.0);

        $transaction = $this->transfer('100000');

        self::assertSame(TransactionStatus::FRAUD_REVIEW, $transaction->getStatus());
        self::assertSame(0.0, $this->to()->getBalance());
    }

    public function testReservedFundsCannotBeSpentTwice(): void
    {
        $this->transfer('600');

        $this->expectException(InsufficientFundsException::class);

        $this->transfer('600');
    }

    public function testCompletingTwiceDoesNotMoveFundsAgain(): void
    {
        $transaction = $this->transfer('100');
        $this->processor->complete($transaction);

        try {
            $this->processor->complete($transaction);
            self::fail('Expected InvalidTransactionStateException.');
        } catch (InvalidTransactionStateException) {
        }

        self::assertSame(900.0, $this->from()->getBalance());
        self::assertSame(23.431, $this->to()->getBalance());
    }

    public function testRejectingCompletedTransactionDoesNotChangeBalances(): void
    {
        $transaction = $this->transfer('100');
        $this->processor->complete($transaction);

        try {
            $this->processor->reject($transaction);
            self::fail('Expected InvalidTransactionStateException.');
        } catch (InvalidTransactionStateException) {
        }

        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
        self::assertSame(900.0, $this->from()->getBalance());
    }

    public function testCompleteRejectsAndReleasesWhenBalanceIsTooLow(): void
    {
        $transaction = $this->transfer('100');
        $this->wallets->rows[self::FROM_WALLET_ID]->setBalance(50.0);

        $this->processor->complete($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertSame(50.0, $this->from()->getBalance());
        self::assertSame(0.0, $this->from()->getReservedBalance());
        self::assertSame(0.0, $this->to()->getBalance());
    }

    public function testCompleteReleasesFundsWhenTargetWalletIsMissing(): void
    {
        $transaction = $this->transfer('100');
        unset($this->wallets->rows[self::TO_WALLET_ID]);

        $this->processor->complete($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertSame(1000.0, $this->from()->getBalance());
        self::assertSame(0.0, $this->from()->getReservedBalance());
    }

    private function transfer(string $amount): Transaction
    {
        return $this->transferService->transfer(1, self::FROM_WALLET_ID, self::TO_WALLET_ID, $amount);
    }

    private function from(): Wallet
    {
        return $this->wallets->findById(self::FROM_WALLET_ID);
    }

    private function to(): Wallet
    {
        return $this->wallets->findById(self::TO_WALLET_ID);
    }
}
