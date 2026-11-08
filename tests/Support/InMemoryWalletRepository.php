<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Wallet;
use App\Enum\Currency;
use App\Repository\WalletRepositoryInterface;

final class InMemoryWalletRepository implements WalletRepositoryInterface
{
    /** @var array<int, Wallet> */
    public array $rows = [];

    public function __construct(private readonly InMemoryDatabase $database)
    {
    }

    public function findById(int $id): ?Wallet
    {
        $this->database->events[] = 'read wallet '.$id;

        // Like the real repository: a deleted wallet is not found.
        if (!isset($this->rows[$id]) || $this->rows[$id]->isDeleted()) {
            return null;
        }

        return clone $this->rows[$id];
    }

    public function lockForUpdate(int ...$ids): void
    {
        foreach ($ids as $id) {
            $this->database->recordLock('wallet '.$id);
        }
    }

    public function findByUserId(int $userId): array
    {
        return [];
    }

    public function findByUserIdAndCurrency(int $userId, Currency $currency): ?Wallet
    {
        return null;
    }

    public function save(Wallet $wallet): void
    {
        $this->database->recordWrite();

        $this->rows[$wallet->getId()] = clone $wallet;
    }

    /** @return array<int, Wallet> */
    public function copyOfRows(): array
    {
        return array_map(static fn (Wallet $wallet): Wallet => clone $wallet, $this->rows);
    }
}
