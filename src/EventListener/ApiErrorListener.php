<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Client errors raised by Symfony itself before a controller runs (invalid ?confirm=, unknown
 * address, wrong method) are answered on /api in the API's own format {"error": "..."} with the
 * same status code, instead of Symfony's HTML error page.
 *
 * Server errors (5xx) and unexpected exceptions are left to Symfony on purpose: in dev it shows
 * the details needed to find the cause, in prod it hides them.
 *
 * Priority -1: after Symfony logs the error (priority 0), so nothing disappears from the logs.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -1)]
final class ApiErrorListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if ('/api' !== $path && !str_starts_with($path, '/api/')) {
            return;
        }

        $exception = $event->getThrowable();
        if (!$exception instanceof HttpExceptionInterface || $exception->getStatusCode() >= 500) {
            return;
        }

        $status = $exception->getStatusCode();
        $message = '' !== $exception->getMessage() ? $exception->getMessage() : (Response::$statusTexts[$status] ?? 'Error');

        $event->setResponse(new JsonResponse(['error' => $message], $status, $exception->getHeaders()));
    }
}
