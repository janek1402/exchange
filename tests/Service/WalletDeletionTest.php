<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotEmptyException;
use App\Exception\WalletNotFoundException;
use App\Service\DepositService;
use App\Service\ExchangeRateService;
use App\Service\SpreadService;
use App\Service\TransferService;
use App\Service\WalletDeletionService;
use App\Tests\Support\InMemoryDatabase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Deleting a wallet must never make a client's money disappear: funds on hold, transfers
 * in progress and any real balance stop it. Only a remainder worth less than 0.01 EUR,
 * which can never be spent, goes to the company (owner's decision).
 */
class WalletDeletionTest extends TestCase
{
    private const int USER_ID = 1;
    private const int WALLET_ID = 1;
    private const int OTHER_WALLET_ID = 2;

    private InMemoryDatabase $database;
    private WalletDeletionService $service;

    protected function setUp(): void
    {
        $this->database = new InMemoryDatabase();
        $this->database->wallets->rows[self::WALLET_ID] = $this->wallet(self::WALLET_ID, Currency::EUR, '0');
        $this->database->wallets->rows[self::OTHER_WALLET_ID] = $this->wallet(self::OTHER_WALLET_ID, Currency::PLN, '1000');

        $this->service = new WalletDeletionService(
            $this->database->wallets,
            $this->database->transactions,
            $this->database->companyWallets,
            new ExchangeRateService(),
            $this->database,
        );
    }

    public function testDeletesOwnEmptyWallet(): void
    {
        $this->service->deleteWallet(self::USER_ID, self::WALLET_ID);

        self::assertTrue($this->database->wallets->rows[self::WALLET_ID]->isDeleted());
        self::assertNotNull($this->database->wallets->rows[self::WALLET_ID]->getDeletedAt());
        self::assertSame([], $this->database->companyWallets->booked);
    }

    public function testDeletedWalletIsNoLongerFound(): void
    {
        $this->service->deleteWallet(self::USER_ID, self::WALLET_ID);

        self::assertNull($this->database->wallets->findById(self::WALLET_ID));
    }

    public function testDeletingTwiceIsNotFound(): void
    {
        $this->service->deleteWallet(self::USER_ID, self::WALLET_ID);

        $this->expectException(WalletNotFoundException::class);

        $this->service->deleteWallet(self::USER_ID, self::WALLET_ID);
    }

    public function testForeignWalletIsNotFound(): void
    {
        $this->database->wallets->rows[3] = $this->wallet(3, Currency::EUR, '0', userId: 2);

        try {
            $this->service->deleteWallet(self::USER_ID, 3);
            self::fail('Expected WalletNotFoundException.');
        } catch (WalletNotFoundException $e) {
            self::assertSame('Wallet 3 not found.', $e->getMessage());
        }

        self::assertFalse($this->database->wallets->rows[3]->isDeleted());
    }

    public function testMissingWalletIsNotFound(): void
    {
        $this->expectException(WalletNotFoundException::class);

        $this->service->deleteWallet(self::USER_ID, 99);
    }

    public function testBlockedWalletCannotBeDeleted(): void
    {
        $this->database->wallets->rows[self::WALLET_ID]->setIsBlocked(true);

        try {
            $this->service->deleteWallet(self::USER_ID, self::WALLET_ID);
            self::fail('Expected WalletBlockedException.');
        } catch (WalletBlockedException) {
        }

        self::assertFalse($this->database->wallets->rows[self::WALLET_ID]->isDeleted());
    }

    public function testWalletWithReservedFundsCannotBeDeleted(): void
    {
        $wallet = $this->database->wallets->rows[self::WALLET_ID];
        $wallet->setBalance('100');
        $wallet->reserve('100');

        $this->assertRefused('Wallet 1 has funds reserved by transfers in progress.');
    }

    #[DataProvider('openStatuses')]
    public function testWalletWithIncomingTransferInProgressCannotBeDeleted(TransactionStatus $status): void
    {
        // An incoming transfer leaves no reservation on this wallet, only a row in transactions.
        $this->storeTransaction(from: self::OTHER_WALLET_ID, to: self::WALLET_ID, status: $status);

        $this->assertRefused('Wallet 1 has transfers in progress.');
    }

    #[DataProvider('openStatuses')]
    public function testWalletWithOutgoingTransferInProgressCannotBeDeleted(TransactionStatus $status): void
    {
        $this->storeTransaction(from: self::WALLET_ID, to: self::OTHER_WALLET_ID, status: $status);

        $this->assertRefused('Wallet 1 has transfers in progress.');
    }

    public static function openStatuses(): iterable
    {
        yield 'pending' => [TransactionStatus::PENDING];
        yield 'fraud review' => [TransactionStatus::FRAUD_REVIEW];
    }

    #[DataProvider('closedStatuses')]
    public function testFinishedTransfersDoNotStopDeletion(TransactionStatus $status): void
    {
        $this->storeTransaction(from: self::OTHER_WALLET_ID, to: self::WALLET_ID, status: $status);

        $this->service->deleteWallet(self::USER_ID, self::WALLET_ID);

        self::assertTrue($this->database->wallets->rows[self::WALLET_ID]->isDeleted());
        self::assertCount(1, $this->database->transactions->rows, 'History must stay.');
    }

    public static function closedStatuses(): iterable
    {
        yield 'completed' => [TransactionStatus::COMPLETED];
        yield 'rejected' => [TransactionStatus::REJECTED];
    }

    /** Exactly 0.01 EUR is a whole cent the client can still spend: not a remainder. */
    public function testWalletWithAWholeCentCannotBeDeleted(): void
    {
        $this->database->wallets->rows[self::WALLET_ID]->setBalance('0.01');

        $this->assertRefused('Wallet 1 still holds 0.0100 EUR. Spend or transfer it first.');
    }

    public function testWalletWithRealMoneyCannotBeDeleted(): void
    {
        $this->database->wallets->rows[self::OTHER_WALLET_ID]->setBalance('12.34');

        try {
            $this->service->deleteWallet(self::USER_ID, self::OTHER_WALLET_ID);
            self::fail('Expected WalletNotEmptyException.');
        } catch (WalletNotEmptyException $e) {
            self::assertSame('Wallet 2 still holds 12.3400 PLN. Spend or transfer it first.', $e->getMessage());
        }

        self::assertSame('12.3400', $this->database->wallets->rows[self::OTHER_WALLET_ID]->getBalance());
    }

    /**
     * 0.009 EUR (part of a cent from old data) and 0.03 PLN (about 0.007 EUR, too little to
     * exchange into EUR) can never be spent: they go to the company, so nothing disappears.
     */
    #[DataProvider('remainders')]
    public function testRemainderWorthLessThanOneEuroCentGoesToTheCompany(Currency $currency, string $balance, string $booked): void
    {
        $this->database->wallets->rows[3] = $this->wallet(3, $currency, $balance);

        $this->service->deleteWallet(self::USER_ID, 3);

        $wallet = $this->database->wallets->rows[3];
        self::assertTrue($wallet->isDeleted());
        self::assertSame('0.0000', $wallet->getBalance());
        self::assertSame([[$currency, $booked]], $this->database->companyWallets->booked);
    }

    public static function remainders(): iterable
    {
        yield 'part of a cent' => [Currency::EUR, '0.009', '0.0090'];
        yield 'too little to exchange' => [Currency::PLN, '0.03', '0.0300'];
        yield 'smallest ledger unit' => [Currency::HUF, '0.0001', '0.0001'];
    }

    public function testRemainderStaysWhenDeletionIsRefused(): void
    {
        $this->database->wallets->rows[self::WALLET_ID]->setBalance('0.009');
        $this->storeTransaction(from: self::OTHER_WALLET_ID, to: self::WALLET_ID, status: TransactionStatus::PENDING);

        $this->assertRefused('Wallet 1 has transfers in progress.');

        self::assertSame('0.0090', $this->database->wallets->rows[self::WALLET_ID]->getBalance());
        self::assertSame([], $this->database->companyWallets->booked);
    }

    /** There are two writes: the remainder for the company and the wallet itself. */
    #[DataProvider('writeNumbers')]
    public function testCrashWhileDeletingChangesNothing(int $failingWrite): void
    {
        $this->database->wallets->rows[self::WALLET_ID]->setBalance('0.009');
        $this->database->failOnWrite($failingWrite);

        try {
            $this->service->deleteWallet(self::USER_ID, self::WALLET_ID);
            self::fail('Expected the simulated crash.');
        } catch (RuntimeException) {
        }

        $wallet = $this->database->wallets->rows[self::WALLET_ID];
        self::assertFalse($wallet->isDeleted());
        self::assertSame('0.0090', $wallet->getBalance());
        self::assertSame([], $this->database->companyWallets->booked);
    }

    public static function writeNumbers(): iterable
    {
        yield 'first write' => [1];
        yield 'second write' => [2];
    }

    /** Without the lock, a transfer to this wallet could slip in between the checks and the delete. */
    public function testWalletIsLockedBeforeItIsRead(): void
    {
        $this->service->deleteWallet(self::USER_ID, self::WALLET_ID);

        self::assertSame(['lock wallet 1', 'read wallet 1'], array_slice($this->database->events, 0, 2));
    }

    public function testTransferToDeletedWalletIsNotFound(): void
    {
        $this->service->deleteWallet(self::USER_ID, self::WALLET_ID);

        $transferService = new TransferService($this->database->wallets, $this->database->transactions, new ExchangeRateService(), new SpreadService(), $this->database);

        $this->expectException(WalletNotFoundException::class);

        $transferService->transfer(self::USER_ID, self::OTHER_WALLET_ID, self::WALLET_ID, '100');
    }

    public function testDepositToDeletedWalletIsNotFound(): void
    {
        $this->service->deleteWallet(self::USER_ID, self::WALLET_ID);

        $this->expectException(WalletNotFoundException::class);

        new DepositService($this->database->wallets, $this->database)->deposit(self::USER_ID, self::WALLET_ID, '100');
    }

    private function assertRefused(string $message): void
    {
        try {
            $this->service->deleteWallet(self::USER_ID, self::WALLET_ID);
            self::fail('Expected WalletNotEmptyException.');
        } catch (WalletNotEmptyException $e) {
            self::assertSame($message, $e->getMessage());
        }

        self::assertFalse($this->database->wallets->rows[self::WALLET_ID]->isDeleted());
    }

    private function storeTransaction(int $from, int $to, TransactionStatus $status): void
    {
        $transaction = Transaction::create(
            fromWalletId: $from,
            toWalletId: $to,
            fromAmount: '100.0000',
            toAmount: '23.4300',
            fromCurrency: Currency::PLN,
            toCurrency: Currency::EUR,
            spread: '0.1611',
            exchangeRate: '0.235910',
            requiresAntiFraudCheck: false,
        );
        $transaction->setStatus($status);

        $this->database->transactions->save($transaction);
    }

    private function wallet(int $id, Currency $currency, string $balance, int $userId = self::USER_ID): Wallet
    {
        return new Wallet($id, $userId, $currency, $balance, false, null, new DateTimeImmutable());
    }
}
