<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\TransactionResponse;
use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\InsufficientFundsException;
use App\Exception\InvalidAmountException;
use App\Exception\InvalidTransactionStateException;
use App\Exception\WalletNotFoundException;
use App\Service\ExchangeRateService;
use App\Service\SpreadService;
use App\Service\TransactionProcessorService;
use App\Service\TransferService;
use App\Tests\Support\InMemoryCompanyWalletRepository;
use App\Tests\Support\InMemoryDatabase;
use App\Tests\Support\InMemoryTransactionRepository;
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
    private InMemoryTransactionRepository $transactions;
    private TransferService $transferService;
    private TransactionProcessorService $processor;

    protected function setUp(): void
    {
        $database = new InMemoryDatabase();
        $this->wallets = $database->wallets;
        $this->companyWallets = $database->companyWallets;
        $this->transactions = $database->transactions;

        $this->wallets->rows[self::FROM_WALLET_ID] = new Wallet(self::FROM_WALLET_ID, 1, Currency::PLN, '1000.0', false, null, new DateTimeImmutable());
        $this->wallets->rows[self::TO_WALLET_ID] = new Wallet(self::TO_WALLET_ID, 1, Currency::EUR, '0.0', false, null, new DateTimeImmutable());

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
        $this->wallets->rows[self::FROM_WALLET_ID]->setBalance('100000.0');

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
        $this->wallets->rows[self::FROM_WALLET_ID]->setBalance('50.0');

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

        self::assertSame('1000.0000', $this->from()->getBalance());
        self::assertSame('100.0000', $this->from()->getReservedBalance());
        self::assertSame('0.0000', $this->to()->getBalance());
    }

    public function testCompleteMovesFundsExactlyOnce(): void
    {
        $this->processor->complete($this->transfer('100'));

        self::assertSame('900.0000', $this->from()->getBalance());
        self::assertSame('0.0000', $this->from()->getReservedBalance());
        self::assertSame('23.4300', $this->to()->getBalance());
    }

    public function testRejectReturnsFundsToSender(): void
    {
        $this->processor->reject($this->transfer('100'));

        self::assertSame('1000.0000', $this->from()->getBalance());
        self::assertSame('0.0000', $this->from()->getReservedBalance());
        self::assertSame('0.0000', $this->to()->getBalance());
    }

    public function testRecipientGetsNothingBeforeFraudReviewApproval(): void
    {
        $this->wallets->rows[self::FROM_WALLET_ID]->setBalance('100000.0');

        $transaction = $this->transfer('100000');

        self::assertSame(TransactionStatus::FRAUD_REVIEW, $transaction->getStatus());
        self::assertSame('0.0000', $this->to()->getBalance());
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

        self::assertSame('900.0000', $this->from()->getBalance());
        self::assertSame('23.4300', $this->to()->getBalance());
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
        self::assertSame('900.0000', $this->from()->getBalance());
    }

    public function testCompleteRejectsAndReleasesWhenBalanceIsTooLow(): void
    {
        $transaction = $this->transfer('100');
        $this->wallets->rows[self::FROM_WALLET_ID]->setBalance('50.0');

        $this->processor->complete($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertSame('50.0000', $this->from()->getBalance());
        self::assertSame('0.0000', $this->from()->getReservedBalance());
        self::assertSame('0.0000', $this->to()->getBalance());
    }

    public function testCompleteReleasesFundsWhenTargetWalletIsMissing(): void
    {
        $transaction = $this->transfer('100');
        unset($this->wallets->rows[self::TO_WALLET_ID]);

        $this->processor->complete($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertSame('1000.0000', $this->from()->getBalance());
        self::assertSame('0.0000', $this->from()->getReservedBalance());
    }

    public function testWholeAvailableBalanceCanBeSpentToTheLastCent(): void
    {
        // 0.05 PLN is the smallest transfer that still gives the client a whole euro cent.
        $this->transfer('999.95');
        $this->transfer('0.05');

        self::assertSame('1000.0000', $this->from()->getReservedBalance());
        self::assertSame('0.0000', $this->from()->getAvailableBalance());

        $this->expectException(InsufficientFundsException::class);

        $this->transfer('0.01');
    }

    public function testTransferIsRefusedWhenConvertedAmountRoundsToZero(): void
    {
        // 0.01 PLN is about 0.0024 EUR: there is no whole cent to give the client.
        try {
            $this->transfer('0.01');
            self::fail('Expected InvalidAmountException.');
        } catch (InvalidAmountException $e) {
            self::assertSame('Amount is too small to exchange.', $e->getMessage());
        }

        self::assertSame('0.0000', $this->from()->getReservedBalance());
        self::assertSame([], $this->transactions->rows);
    }

    public function testCompletedTransferBalancesAreExact(): void
    {
        $this->processor->complete($this->transfer('0.1'));
        $this->processor->complete($this->transfer('0.2'));

        self::assertSame('999.7000', $this->from()->getBalance());
        self::assertSame('0.0000', $this->from()->getReservedBalance());
    }

    /**
     * 100 PLN is 23.591025973719 EUR; the formula spread is 0.1573. The client gets whole
     * cents (23.43), the company everything else, rounded up to its 4-decimal ledger.
     */
    public function testClientReceivesWholeCentsAndCompanyGetsTheRest(): void
    {
        $transaction = $this->transfer('100');

        self::assertSame('23.4300', $transaction->getToAmount());
        self::assertSame('0.1611', $transaction->getSpread());

        $this->processor->complete($transaction);

        self::assertSame('23.4300', $this->to()->getBalance());
        self::assertSame([[Currency::EUR, '0.1611']], $this->companyWallets->booked);
    }

    /** 1 EUR is 185.104803493449 JPY; the formula spread is 1.0889. Yen has no subunit. */
    public function testClientReceivesWholeYen(): void
    {
        $this->wallets->rows[3] = new Wallet(3, 1, Currency::EUR, '10.0', false, null, new DateTimeImmutable());
        $this->wallets->rows[4] = new Wallet(4, 1, Currency::JPY, '0.0', false, null, new DateTimeImmutable());

        $transaction = $this->transferService->transfer(1, 3, 4, '1');

        self::assertSame('184.0000', $transaction->getToAmount());
        self::assertSame('1.1049', $transaction->getSpread());
    }

    public function testTransferOfPartOfAGroszIsRefused(): void
    {
        try {
            $this->transfer('10.005');
            self::fail('Expected InvalidAmountException.');
        } catch (InvalidAmountException $e) {
            self::assertSame('Amount must have at most 2 decimal places for PLN.', $e->getMessage());
        }

        self::assertSame('0.0000', $this->from()->getReservedBalance());
        self::assertSame([], $this->transactions->rows);
    }

    public function testTransferOfPartOfAYenIsRefused(): void
    {
        $this->wallets->rows[3] = new Wallet(3, 1, Currency::JPY, '1000.0', false, null, new DateTimeImmutable());

        $this->expectException(InvalidAmountException::class);
        $this->expectExceptionMessage('Amount must be a whole number for JPY.');

        $this->transferService->transfer(1, 3, self::TO_WALLET_ID, '100.5');
    }

    /** The currency check must not reveal that someone else's wallet exists. */
    public function testForeignWalletIsNotFoundEvenWithInvalidDecimals(): void
    {
        $this->wallets->rows[3] = new Wallet(3, 2, Currency::PLN, '1000.0', false, null, new DateTimeImmutable());

        $this->expectException(WalletNotFoundException::class);

        $this->transferService->transfer(1, 3, self::TO_WALLET_ID, '10.005');
    }

    /**
     * "0100.5", "100.50" and "100.5" are the same money. The transfer's response must show it the
     * way the database stores it and the wallet shows it, not the client's text: otherwise the
     * same transfer reads "0100.5" right after sending and "100.5000" when it is read back.
     */
    public function testSentAmountIsStoredAndShownWithFourDecimals(): void
    {
        foreach (['0100.5', '100.50', '100.5'] as $amount) {
            $transaction = $this->transfer($amount);

            self::assertSame('100.5000', $transaction->getFromAmount(), $amount);
            self::assertSame('100.5000', new TransactionResponse($transaction)->jsonSerialize()['fromAmount'], $amount);
        }

        self::assertSame('301.5000', $this->from()->getReservedBalance());
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
