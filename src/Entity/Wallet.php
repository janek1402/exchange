<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Currency;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

class Wallet
{
    /** Same scale as the DECIMAL(15,4) amounts stored on transactions. */
    private const int LEDGER_PRECISION = 4;

    public function __construct(
        private ?int $id,
        private readonly int $userId,
        private readonly Currency $currency,
        private float $balance,
        private bool $isBlocked,
        private ?DateTimeImmutable $lastActivityAt,
        private readonly DateTimeImmutable $createdAt,
        private float $reservedBalance = 0.0,
    ) {
    }

    public static function create(int $userId, Currency $currency): self
    {
        return new self(
            id: null,
            userId: $userId,
            currency: $currency,
            balance: 0.0,
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

    public function getBalance(): float
    {
        return $this->balance;
    }

    public function getReservedBalance(): float
    {
        return $this->reservedBalance;
    }

    public function reserve(float $amount): void
    {
        $amount = $this->toLedgerAmount($amount);

        $this->reservedBalance = round($this->reservedBalance + $amount, self::LEDGER_PRECISION);
    }

    public function release(float $amount): void
    {
        $amount = $this->toLedgerAmount($amount);
        $reserved = round($this->reservedBalance, self::LEDGER_PRECISION);

        if ($amount > $reserved) {
            throw new LogicException(sprintf('Cannot release %s from wallet %s: only %s is reserved.', $amount, $this->id ?? 'new', $reserved));
        }

        $this->reservedBalance = round($reserved - $amount, self::LEDGER_PRECISION);
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

    public function setBalance(float $balance): void
    {
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

    private function toLedgerAmount(float $amount): float
    {
        $rounded = round($amount, self::LEDGER_PRECISION);

        if ($rounded <= 0.0) {
            throw new InvalidArgumentException(sprintf('Reservation amount must be positive, got %s.', $amount));
        }

        return $rounded;
    }
}
