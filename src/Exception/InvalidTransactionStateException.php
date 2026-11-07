<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\TransactionStatus;
use RuntimeException;

final class InvalidTransactionStateException extends RuntimeException
{
    public function __construct(?int $transactionId, TransactionStatus $status)
    {
        parent::__construct(sprintf('Transaction %s cannot be processed in status "%s".', $transactionId ?? 'new', $status->value));
    }
}
