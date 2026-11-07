<?php

declare(strict_types=1);

namespace App\Repository;

use Throwable;

interface DatabaseTransactionInterface
{
    /**
     * Runs the operation as one database transaction: every write is committed together,
     * or, when the operation throws, every write is undone and the exception is rethrown.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     *
     * @throws Throwable
     */
    public function run(callable $operation): mixed;
}
