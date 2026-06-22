<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\EventSubscriber;

use ApplicationLogger\Bundle\Service\ApiClient;
use ApplicationLogger\Bundle\Service\BreadcrumbCollector;
use ApplicationLogger\Bundle\Service\ContextCollectorInterface;
use ApplicationLogger\Bundle\Service\ErrorPayloadFactory;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Exception Event Subscriber.
 *
 * Captures exceptions and sends them to Application Logger.
 *
 * CRITICAL RESILIENCE GUARANTEE:
 * This subscriber is wrapped in try-catch to ensure it NEVER affects
 * the original exception handling. Even if logging completely fails,
 * the application continues to work normally.
 *
 * Priority is set to -100 to run AFTER all other exception listeners
 * (including those that might handle/suppress the exception).
 */
final class ExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ApiClient $apiClient,
        private readonly ContextCollectorInterface $contextCollector,
        private readonly BreadcrumbCollector $breadcrumbCollector,
        private readonly ErrorPayloadFactory $payloadFactory,
        private readonly bool $debug = false,
        private readonly bool $enabled = true,
        private readonly bool $errorTrackingEnabled = true,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Low priority (-100) to run after other exception handlers
            KernelEvents::EXCEPTION => ['onKernelException', -100],
        ];
    }

    /**
     * Handle kernel exception event.
     *
     * This method is wrapped in try-catch to ensure logging errors
     * never interfere with exception handling.
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        // Runtime gate (master `enabled` may be an env placeholder — AND here, not at compile).
        if (!$this->enabled || !$this->errorTrackingEnabled) {
            return;
        }

        try {
            $exception = $event->getThrowable();

            // Build error payload
            $payload = $this->buildPayload($exception);

            // Send to API (async, fire-and-forget)
            $this->apiClient->sendError($payload);

            // Add breadcrumb about the exception being sent
            $this->breadcrumbCollector->add([
                'type' => 'error',
                'category' => 'exception',
                'message' => \sprintf('Exception captured: %s', $exception->getMessage()),
                'level' => 'error',
            ]);
        } catch (\Throwable $e) {
            // CRITICAL: Never let logging errors affect exception handling
            // Silently fail - the original exception must be processed normally

            if ($this->debug) {
                // Only log in debug mode to avoid noise
                error_log(\sprintf(
                    'ApplicationLogger: Failed to capture exception: %s',
                    $e->getMessage()
                ));
            }

            // Do NOT re-throw - just let it fail silently
        }
    }

    /**
     * Build error payload from exception.
     *
     * Returns payload matching exact API format with snake_case field names.
     * See ErrorIngestDto for complete field specifications. The common ~20-field
     * mapping (including http_method) lives in ErrorPayloadFactory; this method
     * only supplies the subscriber-specific fields (http_status_code + exception tags).
     *
     * @return array<string, mixed>
     */
    private function buildPayload(\Throwable $exception): array
    {
        try {
            $context = $this->contextCollector->collectContext();

            // Extract HTTP status code from exception
            $httpStatusCode = $this->extractHttpStatusCode($exception);

            return $this->payloadFactory->fromThrowable($exception, $context, [
                'http_status_code' => $httpStatusCode,
                'tags' => [
                    'exception_class' => \get_class($exception),
                    'exception_code' => (string) $exception->getCode(),
                ],
            ]);
        } catch (\Throwable) {
            // If payload building fails, return minimal payload.
            return $this->payloadFactory->minimalFallback($exception, [
                'http_status_code' => 500, // Default to 500 for uncaught exceptions
            ]);
        }
    }

    /**
     * Extract HTTP status code from exception.
     *
     * Checks if exception implements HttpExceptionInterface to get status code.
     * Falls back to 500 for uncaught exceptions (internal server error).
     *
     * @return int HTTP status code (100-599)
     */
    private function extractHttpStatusCode(\Throwable $exception): int
    {
        // Check if exception has HTTP status code
        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getStatusCode();
        }

        // Default to 500 Internal Server Error for uncaught exceptions
        return 500;
    }
}
