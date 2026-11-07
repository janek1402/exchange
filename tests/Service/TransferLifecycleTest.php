<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\InsufficientFundsException;
use App\Exception\InvalidTransactionStateException;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\ExchangeRateService;
use App\Service\SpreadService;
use App\Service\TransactionProcessorService;
use App\Service\TransferService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real services together. The in-memory wallet repository hands out a fresh copy
 * on every read, like the database-backed one, so unsaved changes are not visible.
 */
class TransferLifecycleTest extends TestCase
{
    private const int FROM_WALLET_ID = 1;
    private const int TO_WALLET_ID = 2;

    private WalletRepositoryInterface $wallets;
    private TransferService $transferService;
    private TransactionProcessorService $processor;

    protected function setUp(): void
    {
        $this->wallets = new class implements WalletRepositoryInterface {
            /** @var array<int, Wallet> */
            public array $rows = [];

            public function findById(int $id): ?Wallet
            {
                return isset($this->rows[$id]) ? clone $this->rows[$id] : null;
            }

            public function findByUserId(int $userId): array
            {
                return [];
            }

            public function findByUserIdAndCurrency(int $userId, Currency $currency): ?Wallet
            {
                return null;
            }

            public function save(Wallet $wallet): void
            {
                $this->rows[$wallet->getId()] = clone $wallet;
            }
        };

        $transactions = new class implements TransactionRepositoryInterface {
            public function findById(int $id): ?Transaction
            {
                return null;
            }

            public function findByWalletId(int $walletId): array
            {
                return [];
            }

            public function findByStatus(TransactionStatus $status): array
            {
                return [];
            }

            public function save(Transaction $transaction): void
            {
            }
        };

        $this->wallets->rows[self::FROM_WALLET_ID] = new Wallet(self::FROM_WALLET_ID, 1, Currency::PLN, 1000.0, false, null, new DateTimeImmutable());
        $this->wallets->rows[self::TO_WALLET_ID] = new Wallet(self::TO_WALLET_ID, 1, Currency::EUR, 0.0, false, null, new DateTimeImmutable());

        $this->transferService = new TransferService($this->wallets, $transactions, new ExchangeRateService(), new SpreadService());
        $this->processor = new TransactionProcessorService($this->wallets, $transactions);
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
