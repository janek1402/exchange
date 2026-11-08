<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Currency;
use App\Money\Amount;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

class Wallet
{
    /** Decimal string with 4 places, see Amount. */
    private string $balance;

    /** Decimal string with 4 places, see Amount. */
    private string $reservedBalance;

    public function __construct(
        private ?int $id,
        private readonly int $userId,
        private readonly Currency $currency,
        string $balance,
        private bool $isBlocked,
        private ?DateTimeImmutable $lastActivityAt,
        private readonly DateTimeImmutable $createdAt,
        string $reservedBalance = '0',
        private ?DateTimeImmutable $deletedAt = null,
    ) {
        $this->balance = Amount::normalize($balance);
        $this->reservedBalance = Amount::normalize($reservedBalance);
    }

    public static function create(int $userId, Currency $currency): self
    {
        return new self(
            id: null,
            userId: $userId,
            currency: $currency,
            balance: '0',
            isBlocked: false,
            lastActivityAt: null,
            createdAt: new DateTimeImmutable(),
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    public function getBalance(): string
    {
        return $this->balance;
    }

    public function getReservedBalance(): string
    {
        return $this->reservedBalance;
    }

    /** What the client can still spend: the balance minus funds held by pending transfers. */
    public function getAvailableBalance(): string
    {
        return bcsub($this->balance, $this->reservedBalance, Amount::SCALE);
    }

    public function reserve(string $amount): void
    {
        $amount = $this->positiveAmount($amount);

        $this->reservedBalance = bcadd($this->reservedBalance, $amount, Amount::SCALE);
    }

    public function release(string $amount): void
    {
        $amount = $this->positiveAmount($amount);

        if (bccomp($amount, $this->reservedBalance, Amount::SCALE) > 0) {
            throw new LogicException(sprintf('Cannot release %s from wallet %s: only %s is reserved.', $amount, $this->id ?? 'new', $this->reservedBalance));
        }

        $this->reservedBalance = bcsub($this->reservedBalance, $amount, Amount::SCALE);
    }

    public function isBlocked(): bool
    {
        return $this->isBlocked;
    }

    public function getLastActivityAt(): ?DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setBalance(string $balance): void
    {
        $balance = Amount::normalize($balance);

        if (bccomp($balance, '0', Amount::SCALE) < 0) {
            throw new InvalidArgumentException(sprintf('Balance of wallet %s cannot be negative, got %s.', $this->id ?? 'new', $balance));
        }

        $this->balance = $balance;
    }

    public function setIsBlocked(bool $isBlocked): void
    {
        $this->isBlocked = $isBlocked;
    }

    public function setLastActivityAt(?DateTimeImmutable $lastActivityAt): void
    {
        $this->lastActivityAt = $lastActivityAt;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    /**
     * Soft delete: the row stays, because the transaction history points to it.
     * The service decides what to do with a remainder; here the wallet must already be empty.
     */
    public function markDeleted(DateTimeImmutable $deletedAt): void
    {
        if (0 !== bccomp($this->balance, '0', Amount::SCALE) || 0 !== bccomp($this->reservedBalance, '0', Amount::SCALE)) {
            throw new LogicException(sprintf('Wallet %s still holds %s (reserved %s) and cannot be deleted.', $this->id ?? 'new', $this->balance, $this->reservedBalance));
        }

        $this->deletedAt = $deletedAt;
    }

    private function positiveAmount(string $amount): string
    {
        $amount = Amount::normalize($amount);

        if (bccomp($amount, '0', Amount::SCALE) <= 0) {
            throw new InvalidArgumentException(sprintf('Reservation amount must be positive, got %s.', $amount));
        }

        return $amount;
    }
}
