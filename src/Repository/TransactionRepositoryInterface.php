<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Transaction;
use App\Enum\TransactionStatus;

interface TransactionRepositoryInterface
{
    public function findById(int $id): ?Transaction;

    /**
     * Locks the transaction row until the current database transaction ends and updates the
     * given object with the stored status, which another process may have changed since the
     * object was loaded. Must be called inside a transaction.
     */
    public function refreshForUpdate(Transaction $transaction): void;

    /** @return Transaction[] */
    public function findByWalletId(int $walletId): array;

    /** @return Transaction[] */
    public function findByStatus(TransactionStatus $status): array;

    public function save(Transaction $transaction): void;
}
