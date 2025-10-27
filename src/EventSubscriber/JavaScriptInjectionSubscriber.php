<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\EventSubscriber;

use ApplicationLogger\Bundle\Twig\ApplicationLoggerExtension;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Automatically injects ApplicationLogger JavaScript SDK into HTML responses.
 *
 * When auto_inject is enabled, this subscriber adds the JavaScript SDK
 * initialization script before the closing </body> tag of all HTML responses.
 *
 * This provides zero-configuration JavaScript error tracking - users just
 * need to install the bundle and configure the DSN.
 */
class JavaScriptInjectionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly bool $autoInject,
        private readonly bool $enabled,
        private readonly ApplicationLoggerExtension $twigExtension,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -10],
        ];
    }

    /**
     * Inject JavaScript SDK into HTML responses.
     *
     * Only injects when:
     * - JavaScript SDK is enabled
     * - Auto-inject is enabled
     * - It's the main request (not sub-requests)
     * - Response is HTML
     * - Response contains </body> tag
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        // Skip if JavaScript SDK is disabled
        if (!$this->enabled) {
            return;
        }

        // Skip if auto-inject is disabled
        if (!$this->autoInject) {
            return;
        }

        // Only inject on main requests (not sub-requests)
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();

        // Only inject in HTML responses
        $contentType = $response->headers->get('Content-Type', '');
        if (!str_contains($contentType, 'text/html') && !empty($contentType)) {
            return;
        }

        $content = $response->getContent();

        // Skip if no </body> tag found (case-insensitive)
        if (false === $content || false === stripos($content, '</body>')) {
            return;
        }

        try {
            // Generate initialization script
            $script = $this->twigExtension->renderInit();

            // Skip if script generation failed or is empty
            if (empty($script)) {
                if (null !== $this->logger) {
                    $this->logger->debug('ApplicationLogger: JavaScript SDK script generation returned empty result');
                }

                return;
            }

            // Inject before </body> tag (case-insensitive)
            $content = $this->injectScript($content, $script);

            // Validate injection was successful
            if (false === $content) {
                $this->logError('Failed to inject JavaScript SDK: injection returned false');

                return;
            }

            $response->setContent($content);

            if (null !== $this->logger) {
                $this->logger->debug('ApplicationLogger: Successfully injected JavaScript SDK into HTML response');
            }
        } catch (\Throwable $e) {
            // Never throw - resilience is priority
            $this->logError('Failed to inject JavaScript SDK', [
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    /**
     * Inject script before </body> tag.
     *
     * @return string|false Modified content, or false on failure
     */
    private function injectScript(string $content, string $script): string|false
    {
        try {
            // Find the position of </body> (case-insensitive)
            $pos = stripos($content, '</body>');

            if (false === $pos) {
                $this->logError('Could not find </body> tag for injection');

                return $content;
            }

            // Insert script before </body>
            $result = substr_replace($content, $script, $pos, 0);

            // substr_replace can return empty string on edge cases, validate
            if (empty($result)) {
                $this->logError('Script injection resulted in empty content');

                return false;
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logError('Exception during script injection', [
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Log an error message.
     *
     * @param array<string, mixed> $context
     */
    private function logError(string $message, array $context = []): void
    {
        if (null !== $this->logger) {
            $this->logger->error('ApplicationLogger: '.$message, $context);
        }
    }
}
