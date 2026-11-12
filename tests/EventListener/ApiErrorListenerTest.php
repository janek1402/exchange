<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\ApiErrorListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

/**
 * Errors raised by Symfony itself (bad ?confirm=, unknown address, wrong method) must reach a
 * client app in the same {"error": "..."} format as the API's own errors, not as an HTML page.
 */
class ApiErrorListenerTest extends TestCase
{
    #[DataProvider('clientErrors')]
    public function testClientErrorOnApiBecomesJsonWithTheSameStatus(Throwable $exception, int $status, string $message): void
    {
        $event = $this->event('/api/wallets/5', $exception);

        new ApiErrorListener()($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame(['error' => $message], json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public static function clientErrors(): iterable
    {
        yield 'invalid ?confirm=' => [new BadRequestHttpException('Invalid query parameter "confirm".'), 400, 'Invalid query parameter "confirm".'];
        yield 'unknown address' => [new NotFoundHttpException('No route found for "GET /api/walets".'), 404, 'No route found for "GET /api/walets".'];
        yield 'no message' => [new HttpException(404), 404, 'Not Found'];
    }

    /** Tells the client which method to use instead, e.g. POST. */
    public function testWrongMethodKeepsTheAllowHeader(): void
    {
        $event = $this->event('/api/wallets/transfer', new MethodNotAllowedHttpException(['POST'], 'Method Not Allowed (Allow: POST)'));

        new ApiErrorListener()($event);

        self::assertSame(405, $event->getResponse()->getStatusCode());
        self::assertSame('POST', $event->getResponse()->headers->get('Allow'));
    }

    /**
     * A server failure stays with Symfony: in dev it shows the details needed to find the cause,
     * in prod it hides them. Rewriting it here could hide a real bug.
     */
    #[DataProvider('untouched')]
    public function testOtherErrorsAreLeftToSymfony(string $path, Throwable $exception): void
    {
        $event = $this->event($path, $exception);

        new ApiErrorListener()($event);

        self::assertNull($event->getResponse());
    }

    public static function untouched(): iterable
    {
        yield 'unexpected exception' => ['/api/wallets', new RuntimeException('Lock wait timeout exceeded')];
        yield 'server error as HTTP exception' => ['/api/wallets', new HttpException(503, 'Service Unavailable')];
        yield 'not an API address' => ['/', new NotFoundHttpException('No route found for "GET /".')];
        yield 'address that only starts like the API' => ['/apidocs', new NotFoundHttpException('No route found.')];
    }

    private function event(string $path, Throwable $exception): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );
    }
}
