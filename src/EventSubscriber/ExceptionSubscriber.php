<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\EventSubscriber;

use ApplicationLogger\Bundle\Service\ApiClient;
use ApplicationLogger\Bundle\Service\BreadcrumbCollector;
use ApplicationLogger\Bundle\Service\ContextCollector;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
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
class ExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ApiClient $apiClient,
        private readonly ContextCollector $contextCollector,
        private readonly BreadcrumbCollector $breadcrumbCollector,
        private readonly bool $debug = false,
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
        try {
            $exception = $event->getThrowable();
            $request = $event->getRequest();

            // Build error payload
            $payload = $this->buildPayload($exception, $request);

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
     * See ErrorIngestDto for complete field specifications.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(\Throwable $exception, Request $request): array
    {
        try {
            $context = $this->contextCollector->collectContext();

            // Extract HTTP status code from exception
            $httpStatusCode = $this->extractHttpStatusCode($exception);

            // Get session hash if available (GDPR-compliant session tracking)
            $sessionHash = null;
            if ($request->hasSession()) {
                $session = $request->getSession();
                $sessionId = $session->get('_application_logger_session_id');
                if (null !== $sessionId && \is_string($sessionId)) {
                    $sessionHash = hash('sha256', $sessionId);
                }
            }

            return [
                // Required fields (flat structure with snake_case)
                'type' => \get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'stack_trace' => $this->parseStackTrace($exception),

                // Optional fields (all snake_case to match API)
                'level' => 'error',
                'source' => 'backend',
                'environment' => $context['environment'] ?? 'production',
                'release' => $context['release'] ?? null,
                'session_hash' => $sessionHash,
                'timestamp' => new \DateTimeImmutable(),
                'server_name' => $context['server']['name'] ?? null,
                'url' => $context['request']['url'] ?? null,
                'http_method' => $context['request']['method'] ?? null,
                'http_status_code' => $httpStatusCode,
                'ip_address' => $context['request']['ip'] ?? null,
                'user_agent' => $context['request']['user_agent'] ?? null,
                'runtime' => 'PHP '.PHP_VERSION,
                'breadcrumbs' => $this->breadcrumbCollector->get(),
                'request_data' => $context['request'] ?? null,
                'context' => $context['server'] ?? [],
                'tags' => [
                    'exception_class' => \get_class($exception),
                    'exception_code' => (string) $exception->getCode(),
                ],
            ];
        } catch (\Throwable) {
            // If payload building fails, return minimal payload
            return [
                'type' => \get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'stack_trace' => [],
                'level' => 'error',
                'source' => 'backend',
                'timestamp' => new \DateTimeImmutable(),
                'http_status_code' => 500, // Default to 500 for uncaught exceptions
            ];
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

    /**
     * Parse exception stack trace.
     *
     * @return list<array<string, mixed>>
     */
    private function parseStackTrace(\Throwable $exception): array
    {
        try {
            $frames = [];

            foreach ($exception->getTrace() as $trace) {
                $frame = [
                    'file' => $trace['file'] ?? 'unknown',
                    'line' => $trace['line'] ?? 0,
                    'function' => $trace['function'] ?? 'unknown',
                    'class' => $trace['class'] ?? null,
                    'type' => $trace['type'] ?? null,
                ];

                // Determine if frame is in application code (not vendor)
                $frame['in_app'] = !str_contains($frame['file'], '/vendor/');

                $frames[] = $frame;
            }

            // Reverse frames to show root cause first
            return array_reverse($frames);
        } catch (\Throwable) {
            return [];
        }
    }
}
