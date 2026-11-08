<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\TransactionStatus;
use App\Exception\InvalidTransactionStateException;
use App\Money\Amount;
use App\Repository\CompanyWalletRepositoryInterface;
use App\Repository\DatabaseTransactionInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use DateTimeImmutable;

final readonly class TransactionProcessorService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private CompanyWalletRepositoryInterface $companyWalletRepository,
        private DatabaseTransactionInterface $databaseTransaction,
    ) {
    }

    public function complete(Transaction $transaction): void
    {
        $this->databaseTransaction->run(function () use ($transaction): void {
            $this->lockAndAssertProcessable($transaction);

            $this->walletRepository->lockForUpdate($transaction->getFromWalletId(), $transaction->getToWalletId());

            $fromWallet = $this->walletRepository->findById($transaction->getFromWalletId());
            $toWallet = $this->walletRepository->findById($transaction->getToWalletId());
            $fromAmount = $transaction->getFromAmount();

            if (
                null === $fromWallet
                || null === $toWallet
                || $fromWallet->isBlocked()
                || $toWallet->isBlocked()
                || bccomp($fromAmount, $fromWallet->getBalance(), Amount::SCALE) > 0
            ) {
                $this->markRejected($transaction, $fromWallet);

                return;
            }

            $fromWallet->release($fromAmount);
            $fromWallet->setBalance(bcsub($fromWallet->getBalance(), $fromAmount, Amount::SCALE));
            $fromWallet->setLastActivityAt(new DateTimeImmutable());

            $toWallet->setBalance(bcadd($toWallet->getBalance(), $transaction->getToAmount(), Amount::SCALE));
            $toWallet->setLastActivityAt(new DateTimeImmutable());

            $this->walletRepository->save($fromWallet);
            $this->walletRepository->save($toWallet);

            $transaction->setStatus(TransactionStatus::COMPLETED);

            if ($transaction->requiresAntiFraudCheck()) {
                $transaction->setAntiFraudCheckedAt(new DateTimeImmutable());
            }

            $this->transactionRepository->save($transaction);

            $this->companyWalletRepository->addToBalance($transaction->getToCurrency(), $transaction->getSpread());
        });
    }

    public function reject(Transaction $transaction): void
    {
        $this->databaseTransaction->run(function () use ($transaction): void {
            $this->lockAndAssertProcessable($transaction);

            $this->walletRepository->lockForUpdate($transaction->getFromWalletId());

            $this->markRejected($transaction, $this->walletRepository->findById($transaction->getFromWalletId()));
        });
    }

    /**
     * The caller's copy may be out of date, because another run could have processed the
     * transaction since it was loaded. Only the state read under the lock can be trusted.
     */
    private function lockAndAssertProcessable(Transaction $transaction): void
    {
        $this->transactionRepository->refreshForUpdate($transaction);

        $status = $transaction->getStatus();

        if (TransactionStatus::PENDING !== $status && TransactionStatus::FRAUD_REVIEW !== $status) {
            throw new InvalidTransactionStateException($transaction->getId(), $status);
        }
    }

    /** Also releases funds of a blocked wallet: client's money must never stay on hold for good. */
    private function markRejected(Transaction $transaction, ?Wallet $fromWallet): void
    {
        if (null !== $fromWallet) {
            $fromWallet->release($transaction->getFromAmount());
            $this->walletRepository->save($fromWallet);
        }

        $transaction->setStatus(TransactionStatus::REJECTED);

        if ($transaction->requiresAntiFraudCheck()) {
            $transaction->setAntiFraudCheckedAt(new DateTimeImmutable());
        }

        $this->transactionRepository->save($transaction);
    }
}
