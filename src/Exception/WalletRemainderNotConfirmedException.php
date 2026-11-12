<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * The wallet holds only a remainder that cannot be transferred anywhere. Deleting it gives the
 * remainder to the company, so the client has to confirm first.
 */
final class WalletRemainderNotConfirmedException extends RuntimeException
{
}
