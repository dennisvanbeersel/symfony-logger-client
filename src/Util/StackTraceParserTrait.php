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

            foreach ($exception->getTrace() as $trace) {
                $file = $trace['file'] ?? 'unknown';

                $frame = [
                    'file' => $file,
                    // Default to 1 if line is missing (semantically more correct than 0)
                    'line' => $trace['line'] ?? 1,
                    'function' => $trace['function'] ?? 'unknown',
                    'class' => $trace['class'] ?? null,
                    'type' => $trace['type'] ?? null,
                    'in_app' => !str_contains($file, '/vendor/'),
                ];

                $frames[] = $frame;
            }

            // Return frames reversed to show root cause first
            return array_reverse($frames);
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
