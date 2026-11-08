<?php

declare(strict_types=1);

namespace App\Tests\Money;

use App\Enum\Currency;
use App\Exception\InvalidAmountException;
use App\Money\Amount;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AmountTest extends TestCase
{
    #[DataProvider('validAmounts')]
    public function testAcceptsValidClientAmount(string $amount): void
    {
        Amount::assertValid($amount);

        $this->addToAssertionCount(1);
    }

    public static function validAmounts(): iterable
    {
        yield 'integer' => ['1'];
        yield 'two decimals' => ['100.00'];
        yield 'smallest ledger unit' => ['0.0001'];
        yield 'largest value that fits DECIMAL(15,4)' => ['99999999999.9999'];
    }

    #[DataProvider('invalidAmounts')]
    public function testRejectsInvalidClientAmount(string $amount, string $message): void
    {
        $this->expectException(InvalidAmountException::class);
        $this->expectExceptionMessage($message);

        Amount::assertValid($amount);
    }

    public static function invalidAmounts(): iterable
    {
        $notPositive = 'Amount must be a positive number.';

        yield 'zero' => ['0', $notPositive];
        yield 'zero with decimals' => ['0.0000', $notPositive];
        yield 'negative' => ['-5', $notPositive];
        yield 'text' => ['abc', $notPositive];
        yield 'empty' => ['', $notPositive];
        yield 'scientific notation' => ['1e3', $notPositive];
        yield 'leading space' => [' 10', $notPositive];
        yield 'plus sign' => ['+5', $notPositive];
        yield 'trailing dot' => ['10.', $notPositive];
        yield 'leading dot' => ['.5', $notPositive];
        yield 'comma as separator' => ['10,50', $notPositive];
        yield 'more than 4 decimals' => ['0.00001', 'Amount must have at most 4 decimal places.'];
        yield 'does not fit DECIMAL(15,4)' => ['100000000000', 'Amount is too large.'];
    }

    public function testNormalizeWritesFourDecimalPlaces(): void
    {
        self::assertSame('0.1000', Amount::normalize('0.1'));
        self::assertSame('5.0000', Amount::normalize('5'));
        self::assertSame('-5.0000', Amount::normalize('-5'));
        self::assertSame('23.4310', Amount::normalize('23.4310'));
    }

    #[DataProvider('notNormalizable')]
    public function testNormalizeNeverRoundsSilently(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Amount::normalize($value);
    }

    public static function notNormalizable(): iterable
    {
        yield 'more than 4 decimals' => ['1.23456'];
        yield 'text' => ['abc'];
        yield 'scientific notation from a float' => ['1.0E-5'];
    }

    #[DataProvider('amountsThatFitTheCurrency')]
    public function testAcceptsAmountInWholeUnitsOfTheCurrency(string $amount, Currency $currency): void
    {
        Amount::assertFitsCurrency($amount, $currency);

        $this->addToAssertionCount(1);
    }

    public static function amountsThatFitTheCurrency(): iterable
    {
        yield 'cents' => ['10.25', Currency::EUR];
        yield 'trailing zeros are only a format' => ['10.2500', Currency::PLN];
        yield 'HUF fillér' => ['10.25', Currency::HUF];
        yield 'whole yen' => ['100', Currency::JPY];
        yield 'whole yen with zero decimals' => ['100.0000', Currency::JPY];
    }

    #[DataProvider('amountsThatDoNotFitTheCurrency')]
    public function testRejectsAmountSmallerThanTheCurrencyUnit(string $amount, Currency $currency, string $message): void
    {
        $this->expectException(InvalidAmountException::class);
        $this->expectExceptionMessage($message);

        Amount::assertFitsCurrency($amount, $currency);
    }

    public static function amountsThatDoNotFitTheCurrency(): iterable
    {
        yield 'part of a cent' => ['10.005', Currency::EUR, 'Amount must have at most 2 decimal places for EUR.'];
        yield 'part of a grosz' => ['0.0001', Currency::PLN, 'Amount must have at most 2 decimal places for PLN.'];
        yield 'part of a yen' => ['100.5', Currency::JPY, 'Amount must be a whole number for JPY.'];
    }

    #[DataProvider('amountsToFloor')]
    public function testFloorToCurrencyRoundsDownToTheSmallestUnit(string $amount, Currency $currency, string $expected): void
    {
        self::assertSame($expected, Amount::floorToCurrency($amount, $currency));
    }

    public static function amountsToFloor(): iterable
    {
        yield 'part of a cent is cut off' => ['23.433725973719', Currency::EUR, '23.4300'];
        yield 'even 0.99 of a cent' => ['23.439999999999', Currency::EUR, '23.4300'];
        yield 'whole cents stay' => ['249.000000000000', Currency::EUR, '249.0000'];
        yield 'below one cent is zero' => ['0.009999999999', Currency::EUR, '0.0000'];
        yield 'yen to whole units' => ['184.015903493449', Currency::JPY, '184.0000'];
    }
}
