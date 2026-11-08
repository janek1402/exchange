<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\Currency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CurrencyTest extends TestCase
{
    /** Smallest unit a client can hold, after ISO 4217 (HUF: 2, owner's decision). */
    #[DataProvider('decimals')]
    public function testDecimals(Currency $currency, int $expected): void
    {
        self::assertSame($expected, $currency->decimals());
    }

    public static function decimals(): iterable
    {
        yield 'PLN' => [Currency::PLN, 2];
        yield 'EUR' => [Currency::EUR, 2];
        yield 'USD' => [Currency::USD, 2];
        yield 'GBP' => [Currency::GBP, 2];
        yield 'CHF' => [Currency::CHF, 2];
        yield 'HUF' => [Currency::HUF, 2];
        yield 'JPY has no subunit' => [Currency::JPY, 0];
    }
}
