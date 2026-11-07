<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\CompanyWallet;
use App\Enum\Currency;
use App\Repository\CompanyWalletRepositoryInterface;

final class InMemoryCompanyWalletRepository implements CompanyWalletRepositoryInterface
{
    /** @var list<array{Currency, string}> */
    public array $booked = [];

    public function __construct(private readonly InMemoryDatabase $database)
    {
    }

    public function findByCurrency(Currency $currency): ?CompanyWallet
    {
        return null;
    }

    public function findAll(): array
    {
        return [];
    }

    public function addToBalance(Currency $currency, string $amount): void
    {
        $this->database->recordWrite();

        $this->booked[] = [$currency, $amount];
    }
}
