<?php

declare(strict_types=1);

namespace App\Money;

use App\Enum\Currency;
use App\Exception\InvalidAmountException;
use InvalidArgumentException;
use RoundingMode;

/**
 * Money is kept as decimal strings and calculated with bcmath, never with float:
 * a float cannot hold 0.1 exactly, so sums of balances drift away from the ledger.
 */
final class Amount
{
    /** Same scale as the DECIMAL(15,4) money columns in the database. */
    public const int SCALE = 4;

    /** DECIMAL(15,4) leaves 11 digits before the decimal point. */
    private const int MAX_INTEGER_DIGITS = 11;

    /**
     * Checks an amount sent by a client. Plain digits with an optional dot only, so "1e3",
     * " 10" or "10,50" are refused instead of being guessed.
     *
     * @throws InvalidAmountException
     */
    public static function assertValid(string $amount): void
    {
        if (1 !== preg_match('/^(\d+)(?:\.(\d+))?\z/', $amount, $parts)) {
            throw new InvalidAmountException('Amount must be a positive number.');
        }

        if (strlen($parts[2] ?? '') > self::SCALE) {
            throw new InvalidAmountException(sprintf('Amount must have at most %d decimal places.', self::SCALE));
        }

        if (strlen(ltrim($parts[1], '0')) > self::MAX_INTEGER_DIGITS) {
            throw new InvalidAmountException('Amount is too large.');
        }

        if (bccomp($amount, '0', self::SCALE) <= 0) {
            throw new InvalidAmountException('Amount must be a positive number.');
        }
    }

    /**
     * A client can only send or deposit whole units of the currency (cents, whole yen):
     * a part of a cent cannot be paid out. Trailing zeros ("10.2500") are only a format.
     *
     * @throws InvalidAmountException
     */
    public static function assertFitsCurrency(string $amount, Currency $currency): void
    {
        if (0 === bccomp($amount, self::floorToCurrency($amount, $currency), self::SCALE)) {
            return;
        }

        throw new InvalidAmountException(0 === $currency->decimals()
            ? sprintf('Amount must be a whole number for %s.', $currency->value)
            : sprintf('Amount must have at most %d decimal places for %s.', $currency->decimals(), $currency->value));
    }

    /**
     * Rounds down to the smallest unit of the currency, written with 4 decimal places.
     * Used for what the client receives, so any part of a cent stays with the company.
     */
    public static function floorToCurrency(string $amount, Currency $currency): string
    {
        return bcadd(bcround($amount, $currency->decimals(), RoundingMode::NegativeInfinity), '0', self::SCALE);
    }

    /**
     * Brings a ledger value to exactly 4 decimal places. Refuses more decimals instead of
     * rounding them away, because a silently rounded amount is money appearing or vanishing.
     *
     * @throws InvalidArgumentException
     */
    public static function normalize(string $value): string
    {
        if (1 !== preg_match('/^-?\d+(?:\.\d{1,4})?\z/', $value)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a decimal amount with at most %d decimal places.', $value, self::SCALE));
        }

        return bcadd($value, '0', self::SCALE);
    }
}
