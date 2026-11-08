<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\Currency;
use App\Service\SpreadService;
use Generator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SpreadServiceTest extends TestCase
{
    private SpreadService $spreadService;

    protected function setUp(): void
    {
        $this->spreadService = new SpreadService();
    }

    #[DataProvider('calculateSpreadDataProvider')]
    public function testCalculateSpread(
        string $price,
        Currency $fromCurrency,
        Currency $toCurrency,
        string $expectedResult,
    ): void {
        $result = $this->spreadService->calculateSpread($price, $fromCurrency, $toCurrency);

        self::assertSame($expectedResult, $result);
    }

    /**
     * Spread is rounded up to 4 decimals, in the company's favour (owner's decision, step 7).
     * Amounts and the spread column have 4 decimals, so even tiny exchanges can be made.
     */
    public static function calculateSpreadDataProvider(): Generator
    {
        yield 'USD to USD (same currency, no conversion)' => [
            'price' => '100.0',
            'fromCurrency' => Currency::USD,
            'toCurrency' => Currency::USD,
            'expectedResult' => '0.0000',
        ];
        yield 'PLN to PLN (same currency, no conversion)' => [
            'price' => '100.0',
            'fromCurrency' => Currency::PLN,
            'toCurrency' => Currency::PLN,
            'expectedResult' => '0.0000',
        ];
        yield 'HUF to HUF (same currency, no conversion)' => [
            'price' => '100.0',
            'fromCurrency' => Currency::HUF,
            'toCurrency' => Currency::HUF,
            'expectedResult' => '0.0000',
        ];
        // 0.51282...
        yield 'USD to EUR' => [
            'price' => '100.0',
            'fromCurrency' => Currency::USD,
            'toCurrency' => Currency::EUR,
            'expectedResult' => '0.5129',
        ];
        // 0.60606...
        yield 'GBP to CHF' => [
            'price' => '100.0',
            'fromCurrency' => Currency::GBP,
            'toCurrency' => Currency::CHF,
            'expectedResult' => '0.6061',
        ];
        // 0.86956...
        yield 'HUF to JPY' => [
            'price' => '100.0',
            'fromCurrency' => Currency::HUF,
            'toCurrency' => Currency::JPY,
            'expectedResult' => '0.8696',
        ];
        // 0.52631...
        yield 'HUF to PLN' => [
            'price' => '50.0',
            'fromCurrency' => Currency::HUF,
            'toCurrency' => Currency::PLN,
            'expectedResult' => '0.5264',
        ];
        // 1.02564...
        yield 'USD to EUR with higher price' => [
            'price' => '200.0',
            'fromCurrency' => Currency::USD,
            'toCurrency' => Currency::EUR,
            'expectedResult' => '1.0257',
        ];
        // 0.00564... used to round to 0.00 with 2 decimals.
        yield 'USD to EUR small amount' => [
            'price' => '1.10',
            'fromCurrency' => Currency::USD,
            'toCurrency' => Currency::EUR,
            'expectedResult' => '0.0057',
        ];
        // 0.0000315...: far below a cent, still never free.
        yield 'PLN to EUR tiny amount is never free' => [
            'price' => '0.0024',
            'fromCurrency' => Currency::PLN,
            'toCurrency' => Currency::EUR,
            'expectedResult' => '0.0001',
        ];
        // 195 × 0.5% ÷ 0.975 = exactly 1: nothing to round, the client is not overcharged.
        yield 'exact spread is not rounded up' => [
            'price' => '195.0',
            'fromCurrency' => Currency::USD,
            'toCurrency' => Currency::EUR,
            'expectedResult' => '1.0000',
        ];
        // 1.00000051...: a hair above 1, so it goes up to the next 0.0001.
        yield 'spread just above the 4th decimal rounds up' => [
            'price' => '195.0001',
            'fromCurrency' => Currency::USD,
            'toCurrency' => Currency::EUR,
            'expectedResult' => '1.0001',
        ];
    }
}
