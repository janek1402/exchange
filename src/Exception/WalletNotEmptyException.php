<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/** The wallet still has the client's money on it, or money on its way, so it cannot be deleted. */
final class WalletNotEmptyException extends RuntimeException
{
}
