<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Exception\InsufficientFundsException;
use App\Exception\InvalidAmountException;
use App\Exception\SameWalletTransferException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use App\Money\Amount;
use App\Repository\DatabaseTransactionInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use DateTimeImmutable;
use RoundingMode;

readonly class TransferService
{
    private const string ANTI_FRAUD_THRESHOLD_EUR = '15000';

    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private ExchangeRateService $exchangeRateService,
        private SpreadService $spreadService,
        private DatabaseTransactionInterface $databaseTransaction,
    ) {
    }

    public function transfer(
        int $userId,
        int $fromWalletId,
        int $toWalletId,
        string $fromAmount,
    ): Transaction {
        Amount::assertValid($fromAmount);

        return $this->databaseTransaction->run(function () use ($userId, $fromWalletId, $toWalletId, $fromAmount): Transaction {
            $this->walletRepository->lockForUpdate($fromWalletId, $toWalletId);

            $fromWallet = $this->walletRepository->findById($fromWalletId);
            if (null === $fromWallet || $fromWallet->getUserId() !== $userId) {
                throw new WalletNotFoundException($fromWalletId);
            }

            $toWallet = $this->walletRepository->findById($toWalletId);
            if (null === $toWallet || $toWallet->getUserId() !== $userId) {
                throw new WalletNotFoundException($toWalletId);
            }

            if ($fromWalletId === $toWalletId) {
                throw new SameWalletTransferException($fromWalletId);
            }

            if ($fromWallet->isBlocked()) {
                throw new WalletBlockedException($fromWalletId);
            }

            if ($toWallet->isBlocked()) {
                throw new WalletBlockedException($toWalletId);
            }

            if (bccomp($fromAmount, $fromWallet->getAvailableBalance(), Amount::SCALE) > 0) {
                throw new InsufficientFundsException($fromWalletId);
            }

            $fromCurrency = $fromWallet->getCurrency();
            $toCurrency = $toWallet->getCurrency();

            $rawToAmount = $this->exchangeRateService->convert($fromAmount, $fromCurrency, $toCurrency);
            $spread = $this->spreadService->calculateSpread($rawToAmount, $fromCurrency, $toCurrency);
            $toAmount = bcround(bcsub($rawToAmount, $spread, 12), Amount::SCALE, RoundingMode::HalfAwayFromZero);

            // The client would pay and receive nothing (or less than nothing once the spread is taken).
            if (bccomp($toAmount, '0', Amount::SCALE) <= 0) {
                throw new InvalidAmountException('Amount is too small to exchange.');
            }

            // Funds stay on hold until the transaction is completed or rejected.
            $fromWallet->reserve($fromAmount);

            $now = new DateTimeImmutable();
            $fromWallet->setLastActivityAt($now);
            $toWallet->setLastActivityAt($now);

            $this->walletRepository->save($fromWallet);
            $this->walletRepository->save($toWallet);

            $transaction = Transaction::create(
                fromWalletId: $fromWalletId,
                toWalletId: $toWalletId,
                fromAmount: $fromAmount,
                toAmount: $toAmount,
                fromCurrency: $fromCurrency,
                toCurrency: $toCurrency,
                spread: $spread,
                // Stored for history only; the amounts above come from convert().
                exchangeRate: number_format($this->exchangeRateService->getExchangeRateBetween($fromCurrency, $toCurrency), 6, '.', ''),
                requiresAntiFraudCheck: bccomp(
                    $this->exchangeRateService->getValueInEur($fromAmount, $fromCurrency),
                    self::ANTI_FRAUD_THRESHOLD_EUR,
                    12,
                ) > 0,
            );

            $this->transactionRepository->save($transaction);

            return $transaction;
        });
    }
}
