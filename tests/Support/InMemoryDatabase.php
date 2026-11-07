<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Repository\DatabaseTransactionInterface;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Behaves like a database where it matters for money: a failed transaction undoes its writes,
 * and every read returns a fresh copy, so unsaved changes are not visible. It can also simulate
 * a crash on a chosen write, and it logs locks and reads, so their order can be checked.
 *
 * It does not run anything in parallel: real locking is verified against MariaDB, not here.
 */
final class InMemoryDatabase implements DatabaseTransactionInterface
{
    public readonly InMemoryWalletRepository $wallets;
    public readonly InMemoryTransactionRepository $transactions;
    public readonly InMemoryCompanyWalletRepository $companyWallets;

    /** @var list<string> */
    public array $events = [];

    public ?int $failOnWriteNumber = null;

    private int $writeCount = 0;
    private bool $inTransaction = false;

    public function __construct()
    {
        $this->wallets = new InMemoryWalletRepository($this);
        $this->transactions = new InMemoryTransactionRepository($this);
        $this->companyWallets = new InMemoryCompanyWalletRepository($this);
    }

    public function run(callable $operation): mixed
    {
        $wallets = $this->wallets->copyOfRows();
        $transactions = $this->transactions->copyOfRows();
        $booked = $this->companyWallets->booked;

        $this->inTransaction = true;

        try {
            return $operation();
        } catch (Throwable $e) {
            $this->wallets->rows = $wallets;
            $this->transactions->rows = $transactions;
            $this->companyWallets->booked = $booked;

            throw $e;
        } finally {
            $this->inTransaction = false;
        }
    }

    public function recordWrite(): void
    {
        ++$this->writeCount;

        if ($this->writeCount === $this->failOnWriteNumber) {
            $this->failOnWriteNumber = null;

            throw new RuntimeException('Simulated crash during a write.');
        }
    }

    public function recordLock(string $what): void
    {
        if (!$this->inTransaction) {
            throw new LogicException(sprintf('A lock on %s outside a transaction would end immediately.', $what));
        }

        $this->events[] = 'lock '.$what;
    }

    public function failOnWrite(int $number): void
    {
        $this->writeCount = 0;
        $this->failOnWriteNumber = $number;
    }
}
