<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Wallet;
use App\Enum\Currency;

interface WalletRepositoryInterface
{
    public function findById(int $id): ?Wallet;

    /**
     * Locks the wallets until the current database transaction ends, so nobody else can
     * change them in the meantime. Must be called inside a transaction and before any plain
     * read in it: the database then serves later reads from a snapshot taken after the locks.
     */
    public function lockForUpdate(int ...$ids): void;

    /** @return Wallet[] */
    public function findByUserId(int $userId): array;

    public function findByUserIdAndCurrency(int $userId, Currency $currency): ?Wallet;

    public function save(Wallet $wallet): void;
}
