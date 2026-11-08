<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Currency;

class ExchangeRateService
{
    /** Value of one unit in PLN. Kept as text, so money is never calculated from a float. */
    private const array RATES_IN_PLN = [
        Currency::PLN->value => '1.0',
        Currency::EUR->value => '4.2389',
        Currency::USD->value => '3.6467',
        Currency::GBP->value => '4.881',
        Currency::JPY->value => '0.0229',
        Currency::CHF->value => '4.6347',
        Currency::HUF->value => '0.0118',
    ];

    /** Converted amounts keep 12 decimals, rounding to the ledger's 4 happens only at the end. */
    private const int CONVERSION_SCALE = 12;

    public function getExchangeRate(Currency $currency): float
    {
        return (float) self::RATES_IN_PLN[$currency->value];
    }

    /** For display and transaction history only. Use convert() to calculate money. */
    public function getExchangeRateBetween(Currency $from, Currency $to): float
    {
        return $this->getExchangeRate($from) / $this->getExchangeRate($to);
    }

    /**
     * Multiplies first and divides once at the end: a rate between two currencies has
     * endless decimals, and cutting it before multiplying would lose money on large amounts.
     */
    public function convert(string $amount, Currency $from, Currency $to): string
    {
        return bcdiv(
            bcmul($amount, self::RATES_IN_PLN[$from->value], self::CONVERSION_SCALE),
            self::RATES_IN_PLN[$to->value],
            self::CONVERSION_SCALE,
        );
    }

    public function getValueInEur(string $amount, Currency $currency): string
    {
        return $this->convert($amount, $currency, Currency::EUR);
    }
}
