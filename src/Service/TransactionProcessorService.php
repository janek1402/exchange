<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Enum\TransactionStatus;
use App\Exception\InvalidTransactionStateException;
use App\Repository\CompanyWalletRepositoryInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use DateTimeImmutable;

final readonly class TransactionProcessorService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private CompanyWalletRepositoryInterface $companyWalletRepository,
    ) {
    }

    public function complete(Transaction $transaction): void
    {
        $this->assertProcessable($transaction);

        $fromWallet = $this->walletRepository->findById($transaction->getFromWalletId());
        $toWallet = $this->walletRepository->findById($transaction->getToWalletId());
        $fromAmount = (float) $transaction->getFromAmount();

        if (null === $fromWallet || null === $toWallet || $fromAmount > $fromWallet->getBalance()) {
            $this->reject($transaction);

            return;
        }

        $fromWallet->release($fromAmount);
        $fromWallet->setBalance($fromWallet->getBalance() - $fromAmount);
        $fromWallet->setLastActivityAt(new DateTimeImmutable());

        $toWallet->setBalance($toWallet->getBalance() + (float) $transaction->getToAmount());
        $toWallet->setLastActivityAt(new DateTimeImmutable());

        $this->walletRepository->save($fromWallet);
        $this->walletRepository->save($toWallet);

        $transaction->setStatus(TransactionStatus::COMPLETED);

        if ($transaction->requiresAntiFraudCheck()) {
            $transaction->setAntiFraudCheckedAt(new DateTimeImmutable());
        }

        $this->transactionRepository->save($transaction);

        // Booked last: if this write fails, the transaction is already completed and cannot
        // be settled again, so only company revenue is affected, never the client's money.
        $this->companyWalletRepository->addToBalance($transaction->getToCurrency(), $transaction->getSpread());
    }

    public function reject(Transaction $transaction): void
    {
        $this->assertProcessable($transaction);

        $fromWallet = $this->walletRepository->findById($transaction->getFromWalletId());

        if (null !== $fromWallet) {
            $fromWallet->release((float) $transaction->getFromAmount());
            $this->walletRepository->save($fromWallet);
        }

        $transaction->setStatus(TransactionStatus::REJECTED);

        if ($transaction->requiresAntiFraudCheck()) {
            $transaction->setAntiFraudCheckedAt(new DateTimeImmutable());
        }

        $this->transactionRepository->save($transaction);
    }

    private function assertProcessable(Transaction $transaction): void
    {
        $status = $transaction->getStatus();

        if (TransactionStatus::PENDING !== $status && TransactionStatus::FRAUD_REVIEW !== $status) {
            throw new InvalidTransactionStateException($transaction->getId(), $status);
        }
    }
}
