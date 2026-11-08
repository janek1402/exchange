<?php

declare(strict_types=1);

namespace App\Tests\Money;

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
}
