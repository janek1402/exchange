<?php

declare(strict_types=1);

namespace App\Enum;

enum Currency: string
{
    case PLN = 'PLN'; // Polish zloty
    case EUR = 'EUR'; // Euro
    case USD = 'USD'; // US Dollar
    case GBP = 'GBP'; // British Pound
    case JPY = 'JPY'; // Yen
    case CHF = 'CHF'; // Swiss Franc
    case HUF = 'HUF'; // Hungarian Forint

    /**
     * Decimal places of the smallest unit a client can hold (ISO 4217). HUF keeps 2,
     * as in ISO 4217, although fillér coins are no longer in use (owner's decision).
     */
    public function decimals(): int
    {
        return match ($this) {
            self::JPY => 0,
            default => 2,
        };
    }
}
