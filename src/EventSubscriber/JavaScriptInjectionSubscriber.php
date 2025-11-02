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
     * Inject script into HTML content.
     *
     * Implements 3-layer defense-in-depth architecture:
     * 1. Nuclear trap (ultra-minimal) - injected RIGHT AFTER <head> tag (earliest possible)
     * 2. Buffer script (lightweight) - injected BEFORE </head> (early but after nuclear)
     * 3. Module script (full SDK) - injected before </body> (deferred)
     *
     * This order ensures maximum error coverage even in catastrophic failure scenarios.
     *
     * @return string|false Modified content, or false on failure
     */
    private function injectScript(string $content, string $script): string|false
    {
        try {
            // Split script into THREE parts: nuclear, buffer, module
            list($nuclearTrap, $bufferScript, $moduleScript) = $this->splitScript($script);

            // 1. Inject nuclear trap RIGHT AFTER <head> tag (EARLIEST possible execution)
            //    This ensures it runs before ANY other scripts, even those in <head>
            if (!empty($nuclearTrap)) {
                $headOpenPos = stripos($content, '<head>');

                if (false !== $headOpenPos) {
                    // Inject right after <head> opening tag
                    $insertPos = $headOpenPos + \strlen('<head>');
                    $content = substr_replace($content, "\n".$nuclearTrap, $insertPos, 0);

                    if (empty($content)) {
                        $this->logError('Nuclear trap injection after <head> resulted in empty content');

                        return false;
                    }

                    if (null !== $this->logger) {
                        $this->logger->debug('ApplicationLogger: Injected nuclear trap right after <head>');
                    }
                } else {
                    // No <head> tag - this is unusual but handle gracefully
                    if (null !== $this->logger) {
                        $this->logger->warning('ApplicationLogger: No <head> tag found for nuclear trap injection');
                    }
                }
            }

            // 2. Inject buffer script BEFORE </head> (early but after nuclear trap)
            if (!empty($bufferScript)) {
                $headClosePos = stripos($content, '</head>');

                if (false !== $headClosePos) {
                    $content = substr_replace($content, $bufferScript, $headClosePos, 0);

                    if (empty($content)) {
                        $this->logError('Buffer script injection before </head> resulted in empty content');

                        return false;
                    }

                    if (null !== $this->logger) {
                        $this->logger->debug('ApplicationLogger: Injected buffer script before </head>');
                    }
                } else {
                    if (null !== $this->logger) {
                        $this->logger->warning('ApplicationLogger: No </head> tag found for buffer script');
                    }
                }
            }

            // 3. Inject module script before </body> (deferred, full SDK)
            if (!empty($moduleScript)) {
                $bodyPos = stripos($content, '</body>');

                if (false === $bodyPos) {
                    $this->logError('Could not find </body> tag for module script injection');

                    // Already injected nuclear and buffer - still return content
                    return $content;
                }

                $content = substr_replace($content, $moduleScript, $bodyPos, 0);

                if (empty($content)) {
                    $this->logError('Module script injection resulted in empty content');

                    return false;
                }

                if (null !== $this->logger) {
                    $this->logger->debug('ApplicationLogger: Injected module script before </body>');
                }
            }

            return $content;
        } catch (\Throwable $e) {
            $this->logError('Exception during script injection', [
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Split script into THREE parts for 3-layer defense.
     *
     * Layer 1: Nuclear trap (ultra-minimal, captures catastrophic errors)
     * Layer 2: Buffer script (lightweight, captures early errors)
     * Layer 3: Module scripts (full SDK, deferred)
     *
     * Detection strategy:
     * - Nuclear trap: inline script containing '_appLogger_nuclear'
     * - Buffer script: inline script containing '_appLoggerBuffer'
     * - Module scripts: <script type="module">
     *
     * @return array{0: string, 1: string, 2: string} [nuclearTrap, bufferScript, moduleScript]
     */
    private function splitScript(string $script): array
    {
        $nuclearTrap = '';
        $bufferScript = '';
        $moduleScript = '';

        try {
            // Split by script tags to process each individually
            // This is safer than complex regex and handles edge cases better
            $parts = preg_split('/(<script[^>]*>.*?<\/script>)/is', $script, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

            if (false === $parts) {
                throw new \RuntimeException('Failed to split script tags');
            }

            foreach ($parts as $part) {
                $trimmedPart = trim($part);

                if (empty($trimmedPart)) {
                    continue;
                }

                // Check if this is a script tag
                if (!str_starts_with($trimmedPart, '<script')) {
                    // Not a script tag, could be whitespace between tags
                    continue;
                }

                // Check if it's a module script (has type="module" or type='module')
                if (preg_match('/type\s*=\s*["\']module["\']/i', $trimmedPart)) {
                    // This is a module script (Layer 3)
                    $moduleScript .= $trimmedPart."\n";
                } else {
                    // This is an inline script - determine if nuclear or buffer
                    // Nuclear trap contains '_appLogger_nuclear' (Layer 1)
                    if (str_contains($trimmedPart, '_appLogger_nuclear')) {
                        if (empty($nuclearTrap)) {
                            $nuclearTrap = $trimmedPart;
                        } else {
                            // Multiple nuclear traps - unexpected
                            if (null !== $this->logger) {
                                $this->logger->warning('ApplicationLogger: Multiple nuclear traps detected, using first one');
                            }
                        }
                    }
                    // Buffer script contains '_appLoggerBuffer' (Layer 2)
                    elseif (str_contains($trimmedPart, '_appLoggerBuffer')) {
                        if (empty($bufferScript)) {
                            $bufferScript = $trimmedPart;
                        } else {
                            // Multiple buffer scripts - unexpected
                            if (null !== $this->logger) {
                                $this->logger->warning('ApplicationLogger: Multiple buffer scripts detected, using first one');
                            }
                        }
                    }
                    // Unknown inline script - append to module scripts
                    else {
                        if (null !== $this->logger) {
                            $this->logger->warning('ApplicationLogger: Unknown inline script detected, treating as module script');
                        }
                        $moduleScript .= $trimmedPart."\n";
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logError('Failed to split script', [
                'exception' => $e->getMessage(),
            ]);

            // Fallback: treat entire script as module script (safer than losing scripts)
            return ['', '', $script];
        }

        return [$nuclearTrap, $bufferScript, $moduleScript];
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
