<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\InvalidTransactionStateException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use App\Service\DepositService;
use App\Service\ExchangeRateService;
use App\Service\SpreadService;
use App\Service\TransactionProcessorService;
use App\Service\TransferService;
use App\Tests\Support\InMemoryDatabase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Money operations must be all-or-nothing and must work on data nobody else can change meanwhile.
 */
class MoneyConsistencyTest extends TestCase
{
    private const int USER_ID = 1;
    private const int PLN_WALLET_ID = 1;
    private const int EUR_WALLET_ID = 2;

    private InMemoryDatabase $database;
    private TransferService $transferService;
    private TransactionProcessorService $processor;
    private DepositService $depositService;

    protected function setUp(): void
    {
        $this->database = new InMemoryDatabase();

        $this->database->wallets->rows[self::PLN_WALLET_ID] = $this->wallet(self::PLN_WALLET_ID, Currency::PLN, '1000.0');
        $this->database->wallets->rows[self::EUR_WALLET_ID] = $this->wallet(self::EUR_WALLET_ID, Currency::EUR, '500.0');

        $this->transferService = new TransferService(
            $this->database->wallets,
            $this->database->transactions,
            new ExchangeRateService(),
            new SpreadService(),
            $this->database,
        );
        $this->processor = new TransactionProcessorService(
            $this->database->wallets,
            $this->database->transactions,
            $this->database->companyWallets,
            $this->database,
        );
        $this->depositService = new DepositService($this->database->wallets, $this->database);
    }

    /** @return iterable<string, array{int}> */
    public static function writesOfTransfer(): iterable
    {
        yield 'source wallet' => [1];
        yield 'target wallet' => [2];
        yield 'transaction' => [3];
    }

    #[DataProvider('writesOfTransfer')]
    public function testFailedTransferLeavesNoTrace(int $failingWrite): void
    {
        $this->database->failOnWrite($failingWrite);

        try {
            $this->transfer('100');
            self::fail('Expected the simulated crash.');
        } catch (RuntimeException) {
        }

        self::assertSame('1000.0000', $this->pln()->getBalance());
        self::assertSame('0.0000', $this->pln()->getReservedBalance());
        self::assertSame([], $this->database->transactions->rows);
    }

    /** @return iterable<string, array{int}> */
    public static function writesOfComplete(): iterable
    {
        yield 'source wallet' => [1];
        yield 'target wallet' => [2];
        yield 'transaction' => [3];
        yield 'company wallet' => [4];
    }

    #[DataProvider('writesOfComplete')]
    public function testFailedCompleteLeavesNoTraceAndCanBeRepeated(int $failingWrite): void
    {
        $transaction = $this->transfer('100');
        $this->database->failOnWrite($failingWrite);

        try {
            $this->processor->complete($transaction);
            self::fail('Expected the simulated crash.');
        } catch (RuntimeException) {
        }

        self::assertSame('1000.0000', $this->pln()->getBalance());
        self::assertSame('100.0000', $this->pln()->getReservedBalance());
        self::assertSame('500.0000', $this->eur()->getBalance());
        self::assertSame(TransactionStatus::PENDING, $this->stored($transaction)->getStatus());
        self::assertSame([], $this->database->companyWallets->booked);

        $this->processor->complete($transaction);

        self::assertSame('900.0000', $this->pln()->getBalance());
        self::assertSame('0.0000', $this->pln()->getReservedBalance());
        self::assertSame(TransactionStatus::COMPLETED, $this->stored($transaction)->getStatus());
        self::assertCount(1, $this->database->companyWallets->booked);
    }

    /** @return iterable<string, array{int}> */
    public static function writesOfReject(): iterable
    {
        yield 'source wallet' => [1];
        yield 'transaction' => [2];
    }

    #[DataProvider('writesOfReject')]
    public function testFailedRejectLeavesNoTrace(int $failingWrite): void
    {
        $transaction = $this->transfer('100');
        $this->database->failOnWrite($failingWrite);

        try {
            $this->processor->reject($transaction);
            self::fail('Expected the simulated crash.');
        } catch (RuntimeException) {
        }

        self::assertSame('100.0000', $this->pln()->getReservedBalance());
        self::assertSame(TransactionStatus::PENDING, $this->stored($transaction)->getStatus());
    }

    public function testRejectInsideCompleteIsAlsoAllOrNothing(): void
    {
        $transaction = $this->transfer('100');
        unset($this->database->wallets->rows[self::EUR_WALLET_ID]);
        $this->database->failOnWrite(2);

        try {
            $this->processor->complete($transaction);
            self::fail('Expected the simulated crash.');
        } catch (RuntimeException) {
        }

        self::assertSame('100.0000', $this->pln()->getReservedBalance());
        self::assertSame(TransactionStatus::PENDING, $this->stored($transaction)->getStatus());
    }

    public function testTransactionProcessedByAnotherRunIsNotSettledAgain(): void
    {
        $transaction = $this->transfer('100');
        $firstRun = clone $transaction;
        $secondRun = clone $transaction;

        $this->processor->complete($firstRun);

        try {
            $this->processor->complete($secondRun);
            self::fail('Expected InvalidTransactionStateException.');
        } catch (InvalidTransactionStateException) {
        }

        self::assertSame('900.0000', $this->pln()->getBalance());
        self::assertSame('0.0000', $this->pln()->getReservedBalance());
        self::assertCount(1, $this->database->companyWallets->booked);
    }

    public function testTransactionCompletedByAnotherRunCannotBeRejected(): void
    {
        $transaction = $this->transfer('100');
        $firstRun = clone $transaction;
        $secondRun = clone $transaction;

        $this->processor->complete($firstRun);

        $this->expectException(InvalidTransactionStateException::class);

        $this->processor->reject($secondRun);
    }

    public function testTransferLocksWalletsBeforeReadingThem(): void
    {
        $this->transfer('100');

        self::assertSame(
            ['lock wallet 1', 'lock wallet 2', 'read wallet 1', 'read wallet 2'],
            $this->database->events,
        );
    }

    public function testCompleteLocksTransactionThenWalletsBeforeReadingThem(): void
    {
        $transaction = $this->transfer('100');
        $this->database->events = [];

        $this->processor->complete($transaction);

        self::assertSame(
            ['lock transaction 1', 'lock wallet 1', 'lock wallet 2', 'read wallet 1', 'read wallet 2'],
            $this->database->events,
        );
    }

    public function testRejectLocksTransactionThenWalletBeforeReadingIt(): void
    {
        $transaction = $this->transfer('100');
        $this->database->events = [];

        $this->processor->reject($transaction);

        self::assertSame(['lock transaction 1', 'lock wallet 1', 'read wallet 1'], $this->database->events);
    }

    public function testDepositLocksWalletBeforeReadingIt(): void
    {
        $this->depositService->deposit(self::USER_ID, self::PLN_WALLET_ID, '100');

        self::assertSame(['lock wallet 1', 'read wallet 1'], $this->database->events);
    }

    /** @return iterable<string, array{int}> */
    public static function walletsInTransfer(): iterable
    {
        yield 'source wallet' => [self::PLN_WALLET_ID];
        yield 'target wallet' => [self::EUR_WALLET_ID];
    }

    #[DataProvider('walletsInTransfer')]
    public function testTransferInvolvingBlockedWalletIsRefused(int $blockedWalletId): void
    {
        $this->database->wallets->rows[$blockedWalletId]->setIsBlocked(true);

        try {
            $this->transfer('100');
            self::fail('Expected WalletBlockedException.');
        } catch (WalletBlockedException $e) {
            self::assertSame(sprintf('Wallet %d is blocked.', $blockedWalletId), $e->getMessage());
        }

        self::assertSame('0.0000', $this->pln()->getReservedBalance());
        self::assertSame([], $this->database->transactions->rows);
    }

    public function testBlockedWalletOfAnotherUserLooksLikeItDoesNotExist(): void
    {
        $wallet = new Wallet(3, 2, Currency::USD, '0.0', true, null, new DateTimeImmutable());
        $this->database->wallets->rows[3] = $wallet;

        $this->expectException(WalletNotFoundException::class);

        $this->transferService->transfer(self::USER_ID, self::PLN_WALLET_ID, 3, '100');
    }

    /** The wallet can be blocked after the transfer was created, while its funds are on hold. */
    #[DataProvider('walletsInTransfer')]
    public function testCompleteRejectsAndReleasesFundsWhenWalletWasBlockedInTheMeantime(int $blockedWalletId): void
    {
        $transaction = $this->transfer('100');
        $this->database->wallets->rows[$blockedWalletId]->setIsBlocked(true);

        $this->processor->complete($transaction);

        self::assertSame(TransactionStatus::REJECTED, $this->stored($transaction)->getStatus());
        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertSame('1000.0000', $this->pln()->getBalance());
        self::assertSame('0.0000', $this->pln()->getReservedBalance());
        self::assertSame('500.0000', $this->eur()->getBalance());
        self::assertSame([], $this->database->companyWallets->booked);
    }

    public function testRejectReleasesFundsOfBlockedWallet(): void
    {
        $transaction = $this->transfer('100');
        $this->database->wallets->rows[self::PLN_WALLET_ID]->setIsBlocked(true);

        $this->processor->reject($transaction);

        self::assertSame('0.0000', $this->pln()->getReservedBalance());
        self::assertTrue($this->pln()->isBlocked());
    }

    private function transfer(string $amount): Transaction
    {
        return $this->transferService->transfer(self::USER_ID, self::PLN_WALLET_ID, self::EUR_WALLET_ID, $amount);
    }

    private function wallet(int $id, Currency $currency, string $balance): Wallet
    {
        return new Wallet($id, self::USER_ID, $currency, $balance, false, null, new DateTimeImmutable());
    }

    private function pln(): Wallet
    {
        return $this->database->wallets->rows[self::PLN_WALLET_ID];
    }

    private function eur(): Wallet
    {
        return $this->database->wallets->rows[self::EUR_WALLET_ID];
    }

    private function stored(Transaction $transaction): Transaction
    {
        return $this->database->transactions->rows[$transaction->getId()];
    }
}
