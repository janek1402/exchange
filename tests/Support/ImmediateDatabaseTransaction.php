<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Repository\DatabaseTransactionInterface;

/** For tests with mocked repositories, where there is nothing to commit or undo. */
final class ImmediateDatabaseTransaction implements DatabaseTransactionInterface
{
    public function run(callable $operation): mixed
    {
        return $operation();
    }
}
