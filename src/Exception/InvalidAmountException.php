<?php

declare(strict_types=1);

namespace App\Exception;

use InvalidArgumentException;

/** The client sent an amount that cannot be booked. The message is safe to show to the client. */
final class InvalidAmountException extends InvalidArgumentException
{
}
