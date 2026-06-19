<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Util;

/**
 * Shared utilities for parsing stack traces and truncating strings.
 *
 * Used by ExceptionSubscriber and ApplicationLoggerHandler to avoid code duplication.
 */
trait StackTraceParserTrait
{
    /**
     * Maximum number of stack frames captured per exception.
     *
     * Bounds the array built here (and the payload later JSON-encoded by the
     * dispatcher) so a pathological deep-recursion / stack-overflow trace cannot
     * produce an arbitrarily large payload or encode cost. The cap is applied to
     * the innermost frames (closest to the throw site), which carry the most
     * diagnostic value.
     */
    private const MAX_STACK_FRAMES = 100;

    /**
     * Parse exception stack trace.
     *
     * Returns flat array of frames matching API format:
     * [{file, line, function, class, type, in_app}, ...]
     *
     * @return list<array<string, mixed>>
     */
    private function parseStackTrace(\Throwable $exception): array
    {
        try {
            $frames = [];

            // Cap the number of frames captured to bound CPU/memory for pathological
            // (e.g. deep-recursion) traces. getTrace() is ordered innermost-first, so
            // slicing the first MAX_STACK_FRAMES keeps the most relevant frames.
            $trace = $exception->getTrace();
            $truncated = \count($trace) > self::MAX_STACK_FRAMES;
            if ($truncated) {
                $trace = \array_slice($trace, 0, self::MAX_STACK_FRAMES);
            }

            foreach ($trace as $frame) {
                $file = $frame['file'] ?? 'unknown';

                $frames[] = [
                    'file' => $file,
                    // Default to 1 if line is missing (semantically more correct than 0)
                    'line' => $frame['line'] ?? 1,
                    'function' => $frame['function'] ?? 'unknown',
                    'class' => $frame['class'] ?? null,
                    'type' => $frame['type'] ?? null,
                    'in_app' => !str_contains($file, '/vendor/'),
                ];
            }

            // Return frames reversed to show root cause first
            $frames = array_reverse($frames);

            if ($truncated) {
                // Mark truncation at the (now-leading) deepest captured frame so the
                // consumer can tell the trace was cut. array_reverse() preserves no
                // list keys here, so a sentinel frame is the simplest signal.
                array_unshift($frames, [
                    'file' => 'unknown',
                    'line' => 1,
                    'function' => \sprintf('[truncated: stack trace exceeded %d frames]', self::MAX_STACK_FRAMES),
                    'class' => null,
                    'type' => null,
                    'in_app' => false,
                ]);
            }

            return $frames;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Truncate string to maximum length.
     *
     * API has length constraints: type (255), message (1000), file (500).
     * Truncation prevents validation failures.
     */
    private function truncate(string $value, int $maxLength): string
    {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        // Truncate and add ellipsis to indicate truncation
        return mb_substr($value, 0, $maxLength - 3).'...';
    }
}
