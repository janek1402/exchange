<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Wallet;
use App\Enum\Currency;
use App\Exception\InvalidAmountException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use App\Repository\WalletRepositoryInterface;
use App\Service\DepositService;
use App\Tests\Support\ImmediateDatabaseTransaction;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class DepositServiceTest extends TestCase
{
    private WalletRepositoryInterface $walletRepository;
    private DepositService $depositService;

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->depositService = new DepositService($this->walletRepository, new ImmediateDatabaseTransaction());
    }

    public function testDepositSuccessfully(): void
    {
        $userId = 1;
        $wallet = Wallet::create($userId, Currency::PLN);

        $this->walletRepository
            ->expects(self::once())
            ->method('findById')
            ->with(1)
            ->willReturn($wallet);

        $this->walletRepository
            ->expects(self::once())
            ->method('save')
            ->with($wallet);

        $result = $this->depositService->deposit($userId, 1, '500.00');

        self::assertSame('500.0000', $result->getBalance());
        self::assertNotNull($result->getLastActivityAt());
    }

    public function testDepositAddsToExistingBalance(): void
    {
        $userId = 1;
        $wallet = Wallet::create($userId, Currency::EUR);
        $wallet->setBalance('200.0');

        $this->walletRepository
            ->method('findById')
            ->willReturn($wallet);

        $this->depositService->deposit($userId, 1, '300.00');

        self::assertSame('500.0000', $wallet->getBalance());
    }

    public function testTenDepositsOfTenCentsAddUpToExactlyOne(): void
    {
        $wallet = Wallet::create(1, Currency::PLN);

        $this->walletRepository
            ->method('findById')
            ->willReturn($wallet);

        for ($i = 0; $i < 10; ++$i) {
            $this->depositService->deposit(1, 1, '0.10');
        }

        self::assertSame('1.0000', $wallet->getBalance());
    }

    public function testDepositAcceptsExactlyTheMaximumAmount(): void
    {
        $wallet = Wallet::create(1, Currency::PLN);

        $this->walletRepository
            ->method('findById')
            ->willReturn($wallet);

        $this->depositService->deposit(1, 1, '10000.0000');

        self::assertSame('10000.0000', $wallet->getBalance());
    }

    /**
     * The controller checks the amount too, but the service is the last line of defence:
     * any other caller (a command, a future endpoint) must not be able to skip the rules.
     */
    #[DataProvider('invalidDepositAmounts')]
    public function testDepositRejectsInvalidAmountBeforeTouchingTheWallet(string $amount, string $message): void
    {
        $this->walletRepository->expects(self::never())->method('lockForUpdate');
        $this->walletRepository->expects(self::never())->method('findById');
        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(InvalidAmountException::class);
        $this->expectExceptionMessage($message);

        $this->depositService->deposit(1, 1, $amount);
    }

    public static function invalidDepositAmounts(): iterable
    {
        yield 'zero' => ['0', 'Amount must be a positive number.'];
        yield 'negative' => ['-50', 'Amount must be a positive number.'];
        yield 'scientific notation' => ['1e3', 'Amount must be a positive number.'];
        yield 'more than 4 decimals' => ['0.00001', 'Amount must have at most 4 decimal places.'];
        yield 'just above the maximum' => ['10000.0001', 'Amount cannot exceed 10000.'];
    }

    public function testDepositThrowsWhenWalletNotFound(): void
    {
        $this->walletRepository
            ->expects(self::once())
            ->method('findById')
            ->with(99)
            ->willReturn(null);

        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 99 not found.');

        $this->depositService->deposit(1, 99, '100.00');
    }

    public function testDepositThrowsWhenWalletBelongsToOtherUser(): void
    {
        $wallet = Wallet::create(2, Currency::PLN);

        $this->walletRepository
            ->expects(self::once())
            ->method('findById')
            ->with(1)
            ->willReturn($wallet);

        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 1 not found.');

        $this->depositService->deposit(1, 1, '100.00');
    }

    public function testDepositThrowsWhenWalletIsBlocked(): void
    {
        $userId = 1;
        $wallet = Wallet::create($userId, Currency::PLN);
        $wallet->setIsBlocked(true);

        $this->walletRepository
            ->expects(self::once())
            ->method('findById')
            ->with(1)
            ->willReturn($wallet);

        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(WalletBlockedException::class);
        $this->expectExceptionMessage('Wallet 1 is blocked.');

        $this->depositService->deposit($userId, 1, '100.00');
    }
}
