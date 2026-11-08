<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Currency;
use App\Money\Amount;
use RoundingMode;

class SpreadService
{
    private const array LIQUIDITY_SCORE = [
        Currency::USD->value => '1.00',
        Currency::EUR->value => '0.95',
        Currency::GBP->value => '0.85',
        Currency::CHF->value => '0.80',
        Currency::JPY->value => '0.75',
        Currency::PLN->value => '0.55',
        Currency::HUF->value => '0.40',
    ];

    private const string BASE_SPREAD_PERCENT = '0.5';

    private const int SCALE = 16;

    /**
     * spread = price × (0.5% ÷ average pair liquidity), as in README.md.
     */
    public function calculateSpread(
        string $price,
        Currency $fromCurrency,
        Currency $toCurrency,
    ): string {
        if ($fromCurrency === $toCurrency) {
            return '0.0000';
        }

        $fromLiquidity = self::LIQUIDITY_SCORE[$fromCurrency->value];
        $toLiquidity = self::LIQUIDITY_SCORE[$toCurrency->value];

        // Exact: the average of two 2-decimal scores has at most 3 decimals.
        $pairLiquidity = bcdiv(bcadd($fromLiquidity, $toLiquidity, 2), '2', 3);

        // price × BASE% ÷ (pairLiquidity × 100), divided once so nothing is cut too early.
        $spread = bcdiv(
            bcmul($price, self::BASE_SPREAD_PERCENT, self::SCALE),
            bcmul($pairLiquidity, '100', 3),
            self::SCALE,
        );

        // Always up, in the company's favour (AGENTS.md); an exact value stays as is.
        // 4 decimals like all amounts, so tiny exchanges are possible and never free.
        return bcround($spread, Amount::SCALE, RoundingMode::PositiveInfinity);
    }
}
