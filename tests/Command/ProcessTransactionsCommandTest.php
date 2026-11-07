<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ProcessTransactionsCommand;
use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Repository\CompanyWalletRepositoryInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\TransactionProcessorService;
use App\Tests\Support\ImmediateDatabaseTransaction;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ProcessTransactionsCommandTest extends TestCase
{
    private const int BROKEN_TRANSACTION_ID = 7;
    private const int HEALTHY_TRANSACTION_ID = 8;
    private const int FRAUD_REVIEW_TRANSACTION_ID = 9;

    public function testFailedPendingTransactionDoesNotStopTheNextOne(): void
    {
        $broken = $this->transactionWithoutReservation(TransactionStatus::PENDING);
        $healthy = $this->healthyTransaction(TransactionStatus::PENDING);

        $tester = $this->tester(pending: [$broken, $healthy]);
        $exitCode = $tester->execute([]);

        self::assertSame(TransactionStatus::PENDING, $broken->getStatus());
        self::assertSame(TransactionStatus::COMPLETED, $healthy->getStatus());
        self::assertStringContainsString('Transaction #'.self::BROKEN_TRANSACTION_ID, $tester->getDisplay());
        self::assertSame(Command::FAILURE, $exitCode);
    }

    public function testFailedApprovalInFraudReviewDoesNotStopTheNextOne(): void
    {
        $broken = $this->transactionWithoutReservation(TransactionStatus::FRAUD_REVIEW);
        $healthy = $this->healthyTransaction(TransactionStatus::FRAUD_REVIEW);

        $tester = $this->tester(fraudReview: [$broken, $healthy]);
        $tester->setInputs(['yes', 'yes']);
        $exitCode = $tester->execute([]);

        self::assertSame(TransactionStatus::FRAUD_REVIEW, $broken->getStatus());
        self::assertSame(TransactionStatus::COMPLETED, $healthy->getStatus());
        self::assertStringContainsString('Transaction #'.self::BROKEN_TRANSACTION_ID, $tester->getDisplay());
        self::assertSame(Command::FAILURE, $exitCode);
    }

    public function testFailedRejectionInFraudReviewDoesNotStopTheNextOne(): void
    {
        $broken = $this->transactionWithoutReservation(TransactionStatus::FRAUD_REVIEW);
        $healthy = $this->healthyTransaction(TransactionStatus::FRAUD_REVIEW);

        $tester = $this->tester(fraudReview: [$broken, $healthy]);
        $tester->setInputs(['no', 'no']);
        $exitCode = $tester->execute([]);

        self::assertSame(TransactionStatus::FRAUD_REVIEW, $broken->getStatus());
        self::assertSame(TransactionStatus::REJECTED, $healthy->getStatus());
        self::assertSame(Command::FAILURE, $exitCode);
    }

    public function testPressingEnterDoesNotApproveFraudReview(): void
    {
        $transaction = $this->healthyTransaction(TransactionStatus::FRAUD_REVIEW);

        $tester = $this->tester(fraudReview: [$transaction]);
        $tester->setInputs(['']);
        $tester->execute([]);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertNotNull($transaction->getAntiFraudCheckedAt());
    }

    public function testNonInteractiveRunSkipsFraudReviewAndStillCompletesPending(): void
    {
        $fraudReview = $this->transaction(self::FRAUD_REVIEW_TRANSACTION_ID, fromWalletId: 1, status: TransactionStatus::FRAUD_REVIEW);
        $pending = $this->healthyTransaction(TransactionStatus::PENDING);

        $tester = $this->tester(pending: [$pending], fraudReview: [$fraudReview]);
        $exitCode = $tester->execute([], ['interactive' => false]);

        self::assertSame(TransactionStatus::FRAUD_REVIEW, $fraudReview->getStatus());
        self::assertNull($fraudReview->getAntiFraudCheckedAt());
        self::assertStringContainsString('Transaction #'.self::FRAUD_REVIEW_TRANSACTION_ID.' skipped', $tester->getDisplay());
        self::assertSame(TransactionStatus::COMPLETED, $pending->getStatus());
        self::assertSame(Command::SUCCESS, $exitCode);
    }

    public function testNonInteractiveRunDoesNotApproveFraudReview(): void
    {
        $transaction = $this->healthyTransaction(TransactionStatus::FRAUD_REVIEW);

        $tester = $this->tester(fraudReview: [$transaction]);
        $tester->execute([], ['interactive' => false]);

        self::assertNotSame(TransactionStatus::COMPLETED, $transaction->getStatus());
    }

    public function testReturnsSuccessWhenEveryTransactionIsProcessed(): void
    {
        $healthy = $this->healthyTransaction(TransactionStatus::PENDING);

        $tester = $this->tester(pending: [$healthy]);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(TransactionStatus::COMPLETED, $healthy->getStatus());
    }

    /**
     * @param Transaction[] $pending
     * @param Transaction[] $fraudReview
     */
    private function tester(array $pending = [], array $fraudReview = []): CommandTester
    {
        $transactions = $this->createStub(TransactionRepositoryInterface::class);
        $transactions
            ->method('findByStatus')
            ->willReturnCallback(static fn (TransactionStatus $status): array => match ($status) {
                TransactionStatus::PENDING => $pending,
                TransactionStatus::FRAUD_REVIEW => $fraudReview,
                default => [],
            });

        // Enough for two healthy transactions processed in a single run.
        $reservedWallet = $this->wallet(1, Currency::PLN, reserved: 200.0);
        $wallets = $this->createStub(WalletRepositoryInterface::class);
        $wallets
            ->method('findById')
            ->willReturnMap([
                [1, $reservedWallet],
                [2, $this->wallet(2, Currency::EUR, reserved: 0.0)],
                [3, $this->wallet(3, Currency::PLN, reserved: 0.0)],
            ]);

        return new CommandTester(new ProcessTransactionsCommand(
            $transactions,
            new TransactionProcessorService($wallets, $transactions, $this->createStub(CompanyWalletRepositoryInterface::class), new ImmediateDatabaseTransaction()),
        ));
    }

    private function wallet(int $id, Currency $currency, float $reserved): Wallet
    {
        return new Wallet($id, 1, $currency, 500.0, false, null, new DateTimeImmutable(), $reserved);
    }

    private function healthyTransaction(TransactionStatus $status): Transaction
    {
        return $this->transaction(self::HEALTHY_TRANSACTION_ID, fromWalletId: 1, status: $status);
    }

    /** Created before reservations existed, so releasing its amount must fail. */
    private function transactionWithoutReservation(TransactionStatus $status): Transaction
    {
        return $this->transaction(self::BROKEN_TRANSACTION_ID, fromWalletId: 3, status: $status);
    }

    private function transaction(int $id, int $fromWalletId, TransactionStatus $status): Transaction
    {
        return new Transaction(
            id: $id,
            fromWalletId: $fromWalletId,
            toWalletId: 2,
            fromAmount: '100.0000',
            toAmount: '23.4310',
            fromCurrency: Currency::PLN,
            toCurrency: Currency::EUR,
            spread: '0.1600',
            exchangeRate: '0.235910',
            status: $status,
            requiresAntiFraudCheck: TransactionStatus::FRAUD_REVIEW === $status,
            antiFraudCheckedAt: null,
            createdAt: new DateTimeImmutable(),
        );
    }
}
