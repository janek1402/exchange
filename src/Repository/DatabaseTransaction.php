<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

readonly class DatabaseTransaction implements DatabaseTransactionInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function run(callable $operation): mixed
    {
        return $this->connection->transactional(static fn (): mixed => $operation());
    }
}
