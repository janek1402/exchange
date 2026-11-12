<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Currency;
use App\Money\Amount;
use App\Money\ExchangeQuote;
use RoundingMode;

/**
 * The one place that says how much a client receives from an exchange. Used by transfers and
 * by wallet deletion ("can this balance still be transferred?"), so both always agree.
 */
readonly class ExchangeQuoteService
{
    public function __construct(
        private ExchangeRateService $exchangeRateService,
        private SpreadService $spreadService,
    ) {
    }

    public function quote(string $fromAmount, Currency $fromCurrency, Currency $toCurrency): ExchangeQuote
    {
        $rawToAmount = $this->exchangeRateService->convert($fromAmount, $fromCurrency, $toCurrency);
        $formulaSpread = $this->spreadService->calculateSpread($rawToAmount, $fromCurrency, $toCurrency);

        // The client gets whole units of the target currency (cents, whole yen). Everything
        // else is the company's spread, so it is never below the formula and nothing is lost.
        $toAmount = Amount::floorToCurrency(bcsub($rawToAmount, $formulaSpread, 12), $toCurrency);
        $spread = bcround(bcsub($rawToAmount, $toAmount, 12), Amount::SCALE, RoundingMode::PositiveInfinity);

        return new ExchangeQuote($toAmount, $spread);
    }

    /**
     * True when at least one whole cent (or yen) of the balance can be sent to some other
     * currency and the client would receive at least one cent (or yen) there.
     */
    public function canBeTransferred(string $balance, Currency $currency): bool
    {
        $sendable = Amount::floorToCurrency($balance, $currency);

        if (bccomp($sendable, '0', Amount::SCALE) <= 0) {
            return false;
        }

        foreach (Currency::cases() as $toCurrency) {
            if ($toCurrency !== $currency
                && bccomp($this->quote($sendable, $currency, $toCurrency)->toAmount, '0', Amount::SCALE) > 0) {
                return true;
            }
        }

        return false;
    }
}
