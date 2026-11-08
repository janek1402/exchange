<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\WalletController;
use App\Entity\Transaction;
use App\Entity\User;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\InsufficientFundsException;
use App\Exception\InvalidAmountException;
use App\Exception\SameWalletTransferException;
use App\Exception\WalletAlreadyExistsException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotEmptyException;
use App\Exception\WalletNotFoundException;
use App\Repository\WalletRepositoryInterface;
use App\Service\DepositService;
use App\Service\TransferService;
use App\Service\WalletDeletionService;
use App\Service\WalletService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

#[AllowMockObjectsWithoutExpectations]
class WalletControllerTest extends TestCase
{
    private WalletService $walletService;
    private WalletRepositoryInterface $walletRepository;
    private TransferService $transferService;
    private DepositService $depositService;
    private WalletDeletionService $walletDeletionService;
    private WalletController $controller;

    protected function setUp(): void
    {
        $this->walletService = $this->createMock(WalletService::class);
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transferService = $this->createMock(TransferService::class);
        $this->depositService = $this->createMock(DepositService::class);
        $this->walletDeletionService = $this->createMock(WalletDeletionService::class);

        $this->controller = new WalletController(
            $this->walletService,
            $this->walletRepository,
            $this->transferService,
            $this->depositService,
            $this->walletDeletionService,
        );
    }

    /**
     * @throws Throwable
     */
    public function testListReturnsWallets(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());
        $wallet1 = Wallet::create(1, Currency::PLN);
        $wallet2 = Wallet::create(1, Currency::EUR);

        $this->walletRepository
            ->expects(self::once())
            ->method('findByUserId')
            ->with(1)
            ->willReturn([$wallet1, $wallet2]);

        $response = $this->controller->list($user);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @throws Throwable
     */
    public function testCreateWalletSuccessfully(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());
        $wallet = Wallet::create(1, Currency::USD);

        $this->walletService
            ->expects(self::once())
            ->method('createWallet')
            ->with(1, Currency::USD)
            ->willReturn($wallet);

        $request = new Request(content: json_encode(['currency' => 'USD'], JSON_THROW_ON_ERROR));
        $response = $this->controller->create($request, $user);

        self::assertSame(201, $response->getStatusCode());
    }

    /**
     * @throws Throwable
     */
    public function testCreateReturnsBadRequestWhenCurrencyMissing(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $request = new Request(content: json_encode([], JSON_THROW_ON_ERROR));
        $response = $this->controller->create($request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $data);
    }

    /**
     * @throws Throwable
     */
    public function testCreateReturnsBadRequestWhenCurrencyInvalid(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $request = new Request(content: json_encode(['currency' => 'INVALID'], JSON_THROW_ON_ERROR));
        $response = $this->controller->create($request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Invalid currency.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testCreateReturnsConflictWhenWalletAlreadyExists(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->walletService
            ->method('createWallet')
            ->willThrowException(new WalletAlreadyExistsException(1, Currency::PLN));

        $request = new Request(content: json_encode(['currency' => 'PLN'], JSON_THROW_ON_ERROR));
        $response = $this->controller->create($request, $user);

        self::assertSame(409, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Wallet for user 1 in currency PLN already exists.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testTransferSuccessfully(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());
        $transaction = new Transaction(
            id: 42,
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: '100.0000',
            toAmount: '25.1234',
            fromCurrency: Currency::PLN,
            toCurrency: Currency::EUR,
            spread: '0.1260',
            exchangeRate: '0.250000',
            status: TransactionStatus::PENDING,
            requiresAntiFraudCheck: false,
            antiFraudCheckedAt: null,
            createdAt: new DateTimeImmutable(),
        );

        $this->transferService
            ->expects(self::once())
            ->method('transfer')
            ->with(1, 1, 2, '100.00')
            ->willReturn($transaction);

        $request = new Request(content: json_encode([
            'fromWalletId' => 1,
            'toWalletId' => 2,
            'amount' => '100.00',
        ], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(201, $response->getStatusCode());
    }

    /**
     * @throws Throwable
     */
    public function testTransferReturnsBadRequestWhenFromWalletIdMissing(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $request = new Request(content: json_encode(['toWalletId' => 2, 'amount' => '100.00'], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $data);
    }

    /**
     * @throws Throwable
     */
    public function testTransferReturnsBadRequestWhenToWalletIdMissing(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $request = new Request(content: json_encode(['fromWalletId' => 1, 'amount' => '100.00'], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $data);
    }

    /**
     * @throws Throwable
     */
    public function testTransferReturnsBadRequestWhenAmountMissing(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $request = new Request(content: json_encode(['fromWalletId' => 1, 'toWalletId' => 2], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $data);
    }

    /**
     * @throws Throwable
     */
    public function testTransferReturnsBadRequestWhenAmountInvalid(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $request = new Request(content: json_encode([
            'fromWalletId' => 1,
            'toWalletId' => 2,
            'amount' => '-50',
        ], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Amount must be a positive number.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    #[DataProvider('invalidWalletIds')]
    public function testTransferReturnsBadRequestWhenWalletIdInvalid(string $field, mixed $id): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->transferService->expects(self::never())->method('transfer');

        $body = ['fromWalletId' => 1, 'toWalletId' => 2, 'amount' => '100.00'];
        $body[$field] = $id;

        $response = $this->controller->transfer(new Request(content: json_encode($body, JSON_THROW_ON_ERROR)), $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(sprintf('%s must be a positive integer.', $field), $data['error']);
    }

    public static function invalidWalletIds(): iterable
    {
        // Before the fix "abc" was cast to wallet 0 and reported as a missing wallet.
        yield 'text' => ['fromWalletId', 'abc'];
        yield 'number followed by text' => ['toWalletId', '2abc'];
        yield 'zero' => ['fromWalletId', 0];
        yield 'negative' => ['toWalletId', -2];
        yield 'fraction' => ['fromWalletId', 1.5];
        yield 'list' => ['toWalletId', [2]];
    }

    /**
     * @throws Throwable
     */
    public function testTransferAcceptsWalletIdsSentAsDigitStrings(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());
        $transaction = new Transaction(
            id: 10,
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: '100.00',
            toAmount: '25.1234',
            fromCurrency: Currency::PLN,
            toCurrency: Currency::EUR,
            spread: '0.13',
            exchangeRate: '0.250000',
            status: TransactionStatus::PENDING,
            requiresAntiFraudCheck: false,
            antiFraudCheckedAt: null,
            createdAt: new DateTimeImmutable(),
        );

        $this->transferService
            ->expects(self::once())
            ->method('transfer')
            ->with(1, 1, 2, '100.00')
            ->willReturn($transaction);

        $request = new Request(content: json_encode(['fromWalletId' => '1', 'toWalletId' => '2', 'amount' => '100.00'], JSON_THROW_ON_ERROR));

        self::assertSame(201, $this->controller->transfer($request, $user)->getStatusCode());
    }

    /**
     * @throws Throwable
     */
    #[DataProvider('invalidAmounts')]
    public function testTransferReturnsBadRequestForAmountTheLedgerCannotHold(mixed $amount, string $message): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->transferService->expects(self::never())->method('transfer');

        $request = new Request(content: json_encode(['fromWalletId' => 1, 'toWalletId' => 2, 'amount' => $amount], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($message, $data['error']);
    }

    /**
     * @throws Throwable
     */
    #[DataProvider('invalidAmounts')]
    public function testDepositReturnsBadRequestForAmountTheLedgerCannotHold(mixed $amount, string $message): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->depositService->expects(self::never())->method('deposit');

        $request = new Request(content: json_encode(['amount' => $amount], JSON_THROW_ON_ERROR));
        $response = $this->controller->deposit(5, $request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($message, $data['error']);
    }

    public static function invalidAmounts(): iterable
    {
        // Before the fix this amount was accepted and then crashed with 500 on reservation.
        yield 'more than 4 decimals' => ['0.00001', 'Amount must have at most 4 decimal places.'];
        yield 'scientific notation' => ['1e3', 'Amount must be a positive number.'];
        // A JSON number is a float in PHP, so 0.1 + 0.2 style errors would already be in it.
        yield 'JSON number instead of text' => [100.5, 'Amount must be a string, e.g. "100.00".'];
    }

    /**
     * @throws Throwable
     */
    public function testTransferReturnsBadRequestWhenServiceRejectsAmount(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->transferService
            ->method('transfer')
            ->willThrowException(new InvalidAmountException('Amount is too small to exchange.'));

        $request = new Request(content: json_encode(['fromWalletId' => 1, 'toWalletId' => 2, 'amount' => '0.0001'], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Amount is too small to exchange.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testTransferReturnsNotFoundWhenWalletNotFound(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->transferService
            ->method('transfer')
            ->willThrowException(new WalletNotFoundException(99));

        $request = new Request(content: json_encode([
            'fromWalletId' => 99,
            'toWalletId' => 2,
            'amount' => '100.00',
        ], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(404, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Wallet 99 not found.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testTransferReturnsUnprocessableWhenSameWallet(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->transferService
            ->method('transfer')
            ->willThrowException(new SameWalletTransferException(1));

        $request = new Request(content: json_encode([
            'fromWalletId' => 1,
            'toWalletId' => 1,
            'amount' => '100.00',
        ], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Cannot transfer funds within the same wallet 1.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testTransferReturnsUnprocessableWhenInsufficientFunds(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->transferService
            ->method('transfer')
            ->willThrowException(new InsufficientFundsException(1));

        $request = new Request(content: json_encode([
            'fromWalletId' => 1,
            'toWalletId' => 2,
            'amount' => '100.00',
        ], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Insufficient funds in wallet 1.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testTransferReturnsUnprocessableWhenWalletBlocked(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->transferService
            ->method('transfer')
            ->willThrowException(new WalletBlockedException(1));

        $request = new Request(content: json_encode([
            'fromWalletId' => 1,
            'toWalletId' => 2,
            'amount' => '100.00',
        ], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Wallet 1 is blocked.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testDepositSuccessfully(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());
        $wallet = Wallet::create(1, Currency::PLN);

        $this->depositService
            ->expects(self::once())
            ->method('deposit')
            ->with(1, 5, '500.00')
            ->willReturn($wallet);

        $request = new Request(content: json_encode(['amount' => '500.00'], JSON_THROW_ON_ERROR));
        $response = $this->controller->deposit(5, $request, $user);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @throws Throwable
     */
    public function testDepositReturnsBadRequestWhenAmountMissing(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $request = new Request(content: json_encode([], JSON_THROW_ON_ERROR));
        $response = $this->controller->deposit(5, $request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $data);
    }

    /**
     * @throws Throwable
     */
    public function testDepositReturnsBadRequestWhenAmountInvalid(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $request = new Request(content: json_encode(['amount' => '-50'], JSON_THROW_ON_ERROR));
        $response = $this->controller->deposit(5, $request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Amount must be a positive number.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testDepositReturnsBadRequestWhenAmountExceedsMax(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $request = new Request(content: json_encode(['amount' => '99999'], JSON_THROW_ON_ERROR));
        $response = $this->controller->deposit(5, $request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(sprintf('Amount cannot exceed %s.', DepositService::MAX_AMOUNT), $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testDepositReturnsNotFoundWhenWalletNotFound(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->depositService
            ->method('deposit')
            ->willThrowException(new WalletNotFoundException(99));

        $request = new Request(content: json_encode(['amount' => '100.00'], JSON_THROW_ON_ERROR));
        $response = $this->controller->deposit(99, $request, $user);

        self::assertSame(404, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Wallet 99 not found.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testDepositReturnsUnprocessableWhenWalletBlocked(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->depositService
            ->method('deposit')
            ->willThrowException(new WalletBlockedException(5));

        $request = new Request(content: json_encode(['amount' => '100.00'], JSON_THROW_ON_ERROR));
        $response = $this->controller->deposit(5, $request, $user);

        self::assertSame(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Wallet 5 is blocked.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testDeleteReturnsNoContent(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->walletDeletionService
            ->expects(self::once())
            ->method('deleteWallet')
            ->with(1, 5);

        $response = $this->controller->delete(5, $user);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }

    /**
     * Someone else's wallet gets 404, not 403, so its existence is not revealed.
     *
     * @throws Throwable
     */
    #[DataProvider('deleteErrors')]
    public function testDeleteMapsErrors(Throwable $exception, int $status, string $message): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->walletDeletionService->method('deleteWallet')->willThrowException($exception);

        $response = $this->controller->delete(5, $user);

        self::assertSame($status, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($message, $data['error']);
    }

    public static function deleteErrors(): iterable
    {
        yield 'missing or foreign' => [new WalletNotFoundException(5), 404, 'Wallet 5 not found.'];
        yield 'blocked' => [new WalletBlockedException(5), 422, 'Wallet 5 is blocked.'];
        yield 'money on it' => [
            new WalletNotEmptyException('Wallet 5 still holds 12.3400 PLN. Spend or transfer it first.'),
            409,
            'Wallet 5 still holds 12.3400 PLN. Spend or transfer it first.',
        ];
    }
}
