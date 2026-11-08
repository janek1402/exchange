<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\WalletBlockedException;
use App\Exception\WalletNotEmptyException;
use App\Exception\WalletNotFoundException;
use App\Money\Amount;
use App\Repository\CompanyWalletRepositoryInterface;
use App\Repository\DatabaseTransactionInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use DateTimeImmutable;

/**
 * Deletes a client's own wallet (soft delete: the row stays for the transaction history).
 * The client's money must never disappear: funds on hold, transfers in progress and any
 * real balance stop the deletion. Only a remainder worth less than 0.01 EUR, which can never
 * be spent, goes to the company wallet (owner's decision).
 */
readonly class WalletDeletionService
{
    /** Strictly below one euro cent. Do not change without the owner's consent. */
    private const string REMAINDER_LIMIT_EUR = '0.01';

    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private CompanyWalletRepositoryInterface $companyWalletRepository,
        private ExchangeRateService $exchangeRateService,
        private DatabaseTransactionInterface $databaseTransaction,
    ) {
    }

    /**
     * @throws WalletNotFoundException  missing, deleted or someone else's wallet (404, not 403)
     * @throws WalletBlockedException
     * @throws WalletNotEmptyException
     */
    public function deleteWallet(int $userId, int $walletId): void
    {
        $this->databaseTransaction->run(function () use ($userId, $walletId): void {
            // Locked first: a transfer to this wallet locks it too, so none can slip in during the checks.
            $this->walletRepository->lockForUpdate($walletId);

            $wallet = $this->walletRepository->findById($walletId);
            if (null === $wallet || $wallet->getUserId() !== $userId) {
                throw new WalletNotFoundException($walletId);
            }

            if ($wallet->isBlocked()) {
                throw new WalletBlockedException($walletId);
            }

            if (0 !== bccomp($wallet->getReservedBalance(), '0', Amount::SCALE)) {
                throw new WalletNotEmptyException(sprintf('Wallet %d has funds reserved by transfers in progress.', $walletId));
            }

            if ($this->transactionRepository->hasOpenTransactions($walletId)) {
                throw new WalletNotEmptyException(sprintf('Wallet %d has transfers in progress.', $walletId));
            }

            $balance = $wallet->getBalance();
            $valueInEur = $this->exchangeRateService->getValueInEur($balance, $wallet->getCurrency());

            if (bccomp($valueInEur, self::REMAINDER_LIMIT_EUR, 12) >= 0) {
                throw new WalletNotEmptyException(sprintf(
                    'Wallet %d still holds %s %s. Spend or transfer it first.',
                    $walletId,
                    $balance,
                    $wallet->getCurrency()->value,
                ));
            }

            // A remainder that can never be spent goes to the company, so no money disappears.
            if (bccomp($balance, '0', Amount::SCALE) > 0) {
                $this->companyWalletRepository->addToBalance($wallet->getCurrency(), $balance);
                $wallet->setBalance('0');
            }

            $wallet->markDeleted(new DateTimeImmutable());
            $this->walletRepository->save($wallet);
        });
    }
}
