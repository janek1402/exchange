<?php

declare(strict_types=1);

namespace App\Money;

/** What a transfer gives: the client's amount in the target currency and the company's spread. */
final readonly class ExchangeQuote
{
    public function __construct(
        public string $toAmount,
        public string $spread,
    ) {
    }
}
