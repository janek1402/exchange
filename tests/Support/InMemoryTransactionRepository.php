<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Transaction;
use App\Enum\TransactionStatus;
use App\Repository\TransactionRepositoryInterface;
use LogicException;
use ReflectionProperty;

final class InMemoryTransactionRepository implements TransactionRepositoryInterface
{
    /** @var array<int, Transaction> */
    public array $rows = [];

    private int $lastId = 0;

    public function __construct(private readonly InMemoryDatabase $database)
    {
    }

    public function findById(int $id): ?Transaction
    {
        return isset($this->rows[$id]) ? clone $this->rows[$id] : null;
    }

    public function refreshForUpdate(Transaction $transaction): void
    {
        $stored = $this->rows[$transaction->getId()] ?? throw new LogicException('Transaction is not stored.');

        $this->database->recordLock('transaction '.$transaction->getId());

        $transaction->setStatus($stored->getStatus());
        $transaction->setAntiFraudCheckedAt($stored->getAntiFraudCheckedAt());
    }

    public function findByWalletId(int $walletId): array
    {
        return [];
    }

    public function findByStatus(TransactionStatus $status): array
    {
        return [];
    }

    public function save(Transaction $transaction): void
    {
        $this->database->recordWrite();

        if (null === $transaction->getId()) {
            new ReflectionProperty($transaction, 'id')->setValue($transaction, ++$this->lastId);
        }

        $this->rows[$transaction->getId()] = clone $transaction;
    }

    /** @return array<int, Transaction> */
    public function copyOfRows(): array
    {
        return array_map(static fn (Transaction $transaction): Transaction => clone $transaction, $this->rows);
    }
}
