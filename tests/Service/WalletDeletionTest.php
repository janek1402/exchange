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
use App\Exception\WalletRemainderNotConfirmedException;
use App\Service\DepositService;
use App\Service\ExchangeQuoteService;
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
 * in progress and any balance that can still be transferred stop it. Only a remainder that
 * cannot be transferred anywhere goes to the company, after the client confirms (owner's decision).
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
            new ExchangeQuoteService(new ExchangeRateService(), new SpreadService()),
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

        $this->assertRefused('You still have 0.01 EUR that you can transfer. Transfer it before deleting the wallet.');
    }

    public function testWalletWithRealMoneyCannotBeDeleted(): void
    {
        $this->database->wallets->rows[self::OTHER_WALLET_ID]->setBalance('12.34');

        try {
            $this->service->deleteWallet(self::USER_ID, self::OTHER_WALLET_ID);
            self::fail('Expected WalletNotEmptyException.');
        } catch (WalletNotEmptyException $e) {
            self::assertSame('You still have 12.34 PLN that you can transfer. Transfer it before deleting the wallet.', $e->getMessage());
        }

        self::assertSame('12.3400', $this->database->wallets->rows[self::OTHER_WALLET_ID]->getBalance());
    }

    /**
     * 0.009 EUR (part of a cent from old data) and 0.0001 HUF cannot be sent anywhere: once the
     * client confirms, they go to the company, so nothing disappears.
     */
    #[DataProvider('remainders')]
    public function testConfirmedRemainderGoesToTheCompany(Currency $currency, string $balance, string $booked): void
    {
        $this->database->wallets->rows[3] = $this->wallet(3, $currency, $balance);

        $this->service->deleteWallet(self::USER_ID, 3, confirmRemainderLoss: true);

        $wallet = $this->database->wallets->rows[3];
        self::assertTrue($wallet->isDeleted());
        self::assertSame('0.0000', $wallet->getBalance());
        self::assertSame([[$currency, $booked]], $this->database->companyWallets->booked);
    }

    public static function remainders(): iterable
    {
        yield 'part of a cent' => [Currency::EUR, '0.009', '0.0090'];
        yield 'smallest ledger unit' => [Currency::HUF, '0.0001', '0.0001'];
    }

    /**
     * Owner's rule: money the client can still transfer is never lost on deletion, however small.
     * Every amount here gives at least one cent somewhere (calculated with the real rates and spread):
     * 0.04 PLN → 3.35 HUF, 0.01 PLN → 0.83 HUF, 1 JPY → 0.02 PLN, 0.87 HUF → 0.01 PLN.
     */
    #[DataProvider('transferableBalances')]
    public function testBalanceThatCanStillBeTransferredStopsDeletion(Currency $currency, string $balance, string $message): void
    {
        $this->database->wallets->rows[3] = $this->wallet(3, $currency, $balance);

        foreach ([false, true] as $confirmed) {
            try {
                $this->service->deleteWallet(self::USER_ID, 3, confirmRemainderLoss: $confirmed);
                self::fail('Expected WalletNotEmptyException.');
            } catch (WalletNotEmptyException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }

        self::assertFalse($this->database->wallets->rows[3]->isDeleted());
        self::assertSame(bcadd($balance, '0', 4), $this->database->wallets->rows[3]->getBalance());
        self::assertSame([], $this->database->companyWallets->booked);
    }

    public static function transferableBalances(): iterable
    {
        yield '0.04 PLN, worth less than 0.01 EUR' => [Currency::PLN, '0.04', 'You still have 0.04 PLN that you can transfer. Transfer it before deleting the wallet.'];
        // Used to be taken by the company as "too little to exchange"; it gives about 2.5 HUF.
        yield 'too little to exchange into EUR' => [Currency::PLN, '0.03', 'You still have 0.03 PLN that you can transfer. Transfer it before deleting the wallet.'];
        yield 'one grosz' => [Currency::PLN, '0.01', 'You still have 0.01 PLN that you can transfer. Transfer it before deleting the wallet.'];
        yield 'one yen' => [Currency::JPY, '1', 'You still have 1 JPY that you can transfer. Transfer it before deleting the wallet.'];
        yield 'smallest HUF that still gives a grosz' => [Currency::HUF, '0.87', 'You still have 0.87 HUF that you can transfer. Transfer it before deleting the wallet.'];
        yield 'real money' => [Currency::PLN, '12.34', 'You still have 12.34 PLN that you can transfer. Transfer it before deleting the wallet.'];
        // Only whole cents can be sent, so the part of a cent is not offered for transfer.
        yield 'whole cents and a part of a cent' => [Currency::EUR, '10.001', 'You still have 10.00 EUR that you can transfer. Transfer it before deleting the wallet.'];
    }

    /**
     * Nothing can be sent from these: a part of a cent, or HUF giving less than one cent
     * in every currency (0.86 HUF → 0.0099 PLN after the spread). The client is warned first.
     */
    #[DataProvider('unspendableRemainders')]
    public function testUnspendableRemainderNeedsConfirmation(Currency $currency, string $balance, string $message): void
    {
        $this->database->wallets->rows[3] = $this->wallet(3, $currency, $balance);

        try {
            $this->service->deleteWallet(self::USER_ID, 3);
            self::fail('Expected WalletRemainderNotConfirmedException.');
        } catch (WalletRemainderNotConfirmedException $e) {
            self::assertSame($message, $e->getMessage());
        }

        self::assertFalse($this->database->wallets->rows[3]->isDeleted());
        self::assertSame(bcadd($balance, '0', 4), $this->database->wallets->rows[3]->getBalance());
        self::assertSame([], $this->database->companyWallets->booked);
    }

    #[DataProvider('unspendableRemainders')]
    public function testConfirmedUnspendableRemainderGoesToTheCompany(Currency $currency, string $balance, string $warning): void
    {
        unset($warning); // the warning is checked in the test above
        $this->database->wallets->rows[3] = $this->wallet(3, $currency, $balance);

        $this->service->deleteWallet(self::USER_ID, 3, confirmRemainderLoss: true);

        $wallet = $this->database->wallets->rows[3];
        self::assertTrue($wallet->isDeleted());
        self::assertSame('0.0000', $wallet->getBalance());
        self::assertSame([[$currency, bcadd($balance, '0', 4)]], $this->database->companyWallets->booked);
    }

    public static function unspendableRemainders(): iterable
    {
        yield 'part of a euro cent' => [Currency::EUR, '0.001', 'Deleting this wallet will lose the remaining 0.0010 EUR. Send the request again with ?confirm=true to delete it.'];
        yield 'part of a yen' => [Currency::JPY, '0.5', 'Deleting this wallet will lose the remaining 0.5000 JPY. Send the request again with ?confirm=true to delete it.'];
        yield '0.50 HUF' => [Currency::HUF, '0.50', 'Deleting this wallet will lose the remaining 0.5000 HUF. Send the request again with ?confirm=true to delete it.'];
        yield 'largest HUF that gives no grosz' => [Currency::HUF, '0.86', 'Deleting this wallet will lose the remaining 0.8600 HUF. Send the request again with ?confirm=true to delete it.'];
    }

    public function testConfirmationDoesNotMatterForAnEmptyWallet(): void
    {
        $this->service->deleteWallet(self::USER_ID, self::WALLET_ID, confirmRemainderLoss: true);

        self::assertTrue($this->database->wallets->rows[self::WALLET_ID]->isDeleted());
        self::assertSame([], $this->database->companyWallets->booked);
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
            // Confirmed, so the deletion really reaches both writes.
            $this->service->deleteWallet(self::USER_ID, self::WALLET_ID, confirmRemainderLoss: true);
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
