<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Currency;
use App\Money\Amount;
use DateTimeImmutable;

class CompanyWallet
{
    /** Decimal string with 4 places, see Amount. */
    private string $balance;

    public function __construct(
        private ?int $id,
        private readonly Currency $currency,
        string $balance,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
        $this->balance = Amount::normalize($balance);
    }

    public static function create(Currency $currency): self
    {
        $now = new DateTimeImmutable();

        return new self(
            id: null,
            currency: $currency,
            balance: '0',
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    public function getBalance(): string
    {
        return $this->balance;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setBalance(string $balance): void
    {
        $this->balance = Amount::normalize($balance);
    }
}
