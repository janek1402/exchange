<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\WalletBlockedException;
use App\Exception\WalletNotEmptyException;
use App\Exception\WalletNotFoundException;
use App\Exception\WalletRemainderNotConfirmedException;
use App\Money\Amount;
use App\Repository\CompanyWalletRepositoryInterface;
use App\Repository\DatabaseTransactionInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use DateTimeImmutable;

/**
 * Deletes a client's own wallet (soft delete: the row stays for the transaction history).
 * The client's money must never disappear: funds on hold, transfers in progress and any
 * balance that can still be transferred stop the deletion. Only a remainder that cannot be
 * transferred anywhere goes to the company wallet, and only after the client confirms
 * (owner's decision). Do not change this without the owner's consent.
 */
readonly class WalletDeletionService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private CompanyWalletRepositoryInterface $companyWalletRepository,
        private ExchangeQuoteService $exchangeQuoteService,
        private DatabaseTransactionInterface $databaseTransaction,
    ) {
    }

    /**
     * @throws WalletNotFoundException              missing, deleted or someone else's wallet (404, not 403)
     * @throws WalletBlockedException
     * @throws WalletNotEmptyException              funds on hold, transfers in progress or a transferable balance
     * @throws WalletRemainderNotConfirmedException only an untransferable remainder is left and the client has not confirmed losing it
     */
    public function deleteWallet(int $userId, int $walletId, bool $confirmRemainderLoss = false): void
    {
        $this->databaseTransaction->run(function () use ($userId, $walletId, $confirmRemainderLoss): void {
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
            $currency = $wallet->getCurrency();

            if (bccomp($balance, '0', Amount::SCALE) > 0) {
                // Money the client can still transfer is never lost, however small.
                if ($this->exchangeQuoteService->canBeTransferred($balance, $currency)) {
                    throw new WalletNotEmptyException(sprintf(
                        'You still have %s %s that you can transfer. Transfer it before deleting the wallet.',
                        bcadd(Amount::floorToCurrency($balance, $currency), '0', $currency->decimals()),
                        $currency->value,
                    ));
                }

                if (!$confirmRemainderLoss) {
                    throw new WalletRemainderNotConfirmedException(sprintf(
                        'Deleting this wallet will lose the remaining %s %s. Send the request again with ?confirm=true to delete it.',
                        $balance,
                        $currency->value,
                    ));
                }

                // The confirmed remainder goes to the company, so no money disappears.
                $this->companyWalletRepository->addToBalance($currency, $balance);
                $wallet->setBalance('0');
            }

            $wallet->markDeleted(new DateTimeImmutable());
            $this->walletRepository->save($wallet);
        });
    }
}
