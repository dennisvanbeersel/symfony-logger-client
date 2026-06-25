<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\EventSubscriber;

use ApplicationLogger\Bundle\Service\ContextCollectorInterface;
use ApplicationLogger\Bundle\Service\Sdk\SdkClientFactory;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Exception Event Subscriber.
 *
 * Captures exceptions and forwards them to the sdk-core Hub via SdkClientFactory.
 * Enriches the per-request Scope with user context and exception tags before capture.
 * Full request/server context flows automatically via BundleContextCollector inside
 * sdk-core's Client.
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
        private readonly SdkClientFactory $factory,
        private readonly ContextCollectorInterface $contextCollector,
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
     * Enriches the sdk-core Scope with user context and exception tags,
     * then delegates capture to the Hub. Never throws into the host app.
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        // Runtime gate (master `enabled` may be an env placeholder — AND here, not at compile).
        if (!$this->enabled || !$this->errorTrackingEnabled) {
            return;
        }

        try {
            $hub = $this->factory->getHub();
            $exception = $event->getThrowable();
            $scope = $hub->getScope();

            $user = $this->contextCollector->collectUser();
            if (\is_array($user)) {
                $scope->setUser($user);
            }

            $scope->setTag('exception_class', $exception::class);
            $scope->setTag(
                'http_status_code',
                (string) ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500),
            );

            $hub->captureException($exception);
        } catch (\Throwable $e) {
            // CRITICAL: Never let logging errors affect exception handling.
            // Silently fail — the original exception must be processed normally.
            if ($this->debug) {
                error_log('ApplicationLogger: Failed to capture exception: '.$e->getMessage());
            }
        }
    }
}
