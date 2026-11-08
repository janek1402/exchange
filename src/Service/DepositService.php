<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Wallet;
use App\Exception\InvalidAmountException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use App\Money\Amount;
use App\Repository\DatabaseTransactionInterface;
use App\Repository\WalletRepositoryInterface;
use DateTimeImmutable;

readonly class DepositService
{
    public const string MAX_AMOUNT = '10000';

    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private DatabaseTransactionInterface $databaseTransaction,
    ) {
    }

    /**
     * @throws InvalidAmountException
     */
    public static function assertValidAmount(string $amount): void
    {
        Amount::assertValid($amount);

        if (bccomp($amount, self::MAX_AMOUNT, Amount::SCALE) > 0) {
            throw new InvalidAmountException(sprintf('Amount cannot exceed %s.', self::MAX_AMOUNT));
        }
    }

    public function deposit(int $userId, int $walletId, string $amount): Wallet
    {
        self::assertValidAmount($amount);

        return $this->databaseTransaction->run(function () use ($userId, $walletId, $amount): Wallet {
            $this->walletRepository->lockForUpdate($walletId);

            $wallet = $this->walletRepository->findById($walletId);
            if (null === $wallet || $wallet->getUserId() !== $userId) {
                throw new WalletNotFoundException($walletId);
            }

            if ($wallet->isBlocked()) {
                throw new WalletBlockedException($walletId);
            }

            // Only after the ownership check, so the error does not reveal a foreign wallet's currency.
            Amount::assertFitsCurrency($amount, $wallet->getCurrency());

            $wallet->setBalance(bcadd($wallet->getBalance(), $amount, Amount::SCALE));
            $wallet->setLastActivityAt(new DateTimeImmutable());
            $this->walletRepository->save($wallet);

            return $wallet;
        });
    }
}
