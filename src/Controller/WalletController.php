<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\TransactionResponse;
use App\Dto\WalletResponse;
use App\Entity\User;
use App\Enum\Currency;
use App\Exception\InsufficientFundsException;
use App\Exception\InvalidAmountException;
use App\Exception\SameWalletTransferException;
use App\Exception\WalletAlreadyExistsException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotEmptyException;
use App\Exception\WalletNotFoundException;
use App\Exception\WalletRemainderNotConfirmedException;
use App\Money\Amount;
use App\Repository\WalletRepositoryInterface;
use App\Service\DepositService;
use App\Service\TransferService;
use App\Service\WalletDeletionService;
use App\Service\WalletService;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use ValueError;

#[Route('/api/wallets')]
final class WalletController extends AbstractController
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly WalletRepositoryInterface $walletRepository,
        private readonly TransferService $transferService,
        private readonly DepositService $depositService,
        private readonly WalletDeletionService $walletDeletionService,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $wallets = $this->walletRepository->findByUserId($user->getIdNotNull());

        return new JsonResponse(array_map(static fn ($w) => new WalletResponse($w), $wallets));
    }

    /**
     * @throws JsonException
     */
    #[Route('', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR) ?? [];

        if (!isset($data['currency'])) {
            return new JsonResponse(['error' => 'Missing required field: currency.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $currency = Currency::from($data['currency']);
        } catch (ValueError) {
            return new JsonResponse(['error' => 'Invalid currency.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $wallet = $this->walletService->createWallet($user->getIdNotNull(), $currency);
        } catch (WalletAlreadyExistsException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return new JsonResponse(new WalletResponse($wallet), Response::HTTP_CREATED);
    }

    /**
     * @throws JsonException
     */
    #[Route('/transfer', methods: ['POST'])]
    public function transfer(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR) ?? [];

        foreach (['fromWalletId', 'toWalletId', 'amount'] as $field) {
            if (!isset($data[$field])) {
                return new JsonResponse(['error' => sprintf('Missing required field: %s.', $field)], Response::HTTP_BAD_REQUEST);
            }
        }

        $walletIds = [];
        foreach (['fromWalletId', 'toWalletId'] as $field) {
            $walletIds[$field] = $this->parseWalletId($data[$field]);

            if (null === $walletIds[$field]) {
                return new JsonResponse(['error' => sprintf('%s must be a positive integer.', $field)], Response::HTTP_BAD_REQUEST);
            }
        }

        try {
            $amount = $this->parseAmount($data['amount']);
            Amount::assertValid($amount);

            $transaction = $this->transferService->transfer(
                $user->getIdNotNull(),
                $walletIds['fromWalletId'],
                $walletIds['toWalletId'],
                $amount,
            );
        } catch (InvalidAmountException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (WalletNotFoundException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (SameWalletTransferException|InsufficientFundsException|WalletBlockedException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(new TransactionResponse($transaction), Response::HTTP_CREATED);
    }

    /**
     * @throws JsonException
     */
    #[Route('/{id}/deposit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deposit(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR) ?? [];

        if (!isset($data['amount'])) {
            return new JsonResponse(['error' => 'Missing required field: amount.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $amount = $this->parseAmount($data['amount']);
            DepositService::assertValidAmount($amount);

            $wallet = $this->depositService->deposit(
                $user->getIdNotNull(),
                $id,
                $amount,
            );
        } catch (InvalidAmountException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (WalletNotFoundException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (WalletBlockedException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(new WalletResponse($wallet));
    }

    /**
     * Someone else's wallet gets 404, not 403, so its existence is not revealed.
     * ?confirm=true is the client's consent to lose a remainder that cannot be transferred.
     */
    #[Route('/{id}', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] User $user, #[MapQueryParameter(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] bool $confirm = false): Response
    {
        try {
            $this->walletDeletionService->deleteWallet($user->getIdNotNull(), $id, $confirm);
        } catch (WalletNotFoundException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (WalletBlockedException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (WalletNotEmptyException|WalletRemainderNotConfirmedException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * A JSON number becomes a float in PHP and may already be off (0.1 + 0.2 style), so only
     * text and whole numbers are accepted. The services validate the value itself.
     *
     * @throws InvalidAmountException
     */
    private function parseAmount(mixed $amount): string
    {
        if (is_int($amount)) {
            return (string) $amount;
        }

        if (!is_string($amount)) {
            throw new InvalidAmountException('Amount must be a string, e.g. "100.00".');
        }

        return $amount;
    }

    /** Without this, "abc" would be cast to wallet 0 and reported as a missing wallet. */
    private function parseWalletId(mixed $id): ?int
    {
        if (is_string($id) && 1 === preg_match('/^[1-9]\d{0,17}\z/', $id)) {
            $id = (int) $id;
        }

        return is_int($id) && $id > 0 ? $id : null;
    }
}
