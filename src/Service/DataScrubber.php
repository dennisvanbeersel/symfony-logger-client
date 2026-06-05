<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Service;

/**
 * Shared, stateless utility for GDPR-compliant data scrubbing and IP anonymization.
 *
 * Extracted so the Monolog handler, session tracking, and context collection all
 * apply the SAME, user-configurable scrub-field list and the SAME IP-masking logic.
 *
 * RESILIENCE: Every public method is total - it never throws. On any internal
 * failure it returns a safe default (redacted/empty/null) rather than leaking data
 * or crashing the host application.
 */
final class DataScrubber
{
    /**
     * @param list<string> $scrubFields field-name fragments to redact (case-insensitive substring match)
     */
    public function __construct(
        private readonly array $scrubFields,
    ) {
    }

    /**
     * Recursively scrub sensitive values from an array.
     *
     * A key is redacted when its name contains (case-insensitively) any configured
     * scrub fragment. Nested arrays are scrubbed recursively, so structures such as
     * ['user' => ['password' => 'x']] no longer leak. Recursion is depth-limited to
     * avoid pathological / cyclic structures exhausting the stack.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    public function scrub(array $data, int $maxDepth = 16): array
    {
        try {
            return $this->scrubInternal($data, $maxDepth);
        } catch (\Throwable) {
            // Fail safe: an empty array can never leak sensitive data.
            return [];
        }
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function scrubInternal(array $data, int $depth): array
    {
        if ($depth <= 0) {
            return [];
        }

        $scrubbed = [];
        foreach ($data as $key => $value) {
            if ($this->keyIsSensitive((string) $key)) {
                $scrubbed[$key] = '[REDACTED]';
                continue;
            }

            $scrubbed[$key] = \is_array($value)
                ? $this->scrubInternal($value, $depth - 1)
                : $value;
        }

        return $scrubbed;
    }

    private function keyIsSensitive(string $key): bool
    {
        foreach ($this->scrubFields as $field) {
            if ('' !== $field && false !== stripos($key, $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Anonymize an IP address for GDPR data-minimisation.
     *
     * IPv4: mask the last octet (192.168.1.100 -> 192.168.1.0).
     * IPv6: keep the first 48 bits, zero the remaining 80 bits.
     * Returns null on null input or any failure (never the raw IP on error).
     */
    public function anonymizeIp(?string $ip): ?string
    {
        if (null === $ip || '' === $ip) {
            return null;
        }

        try {
            if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
                $parts = explode('.', $ip);
                $parts[3] = '0';

                return implode('.', $parts);
            }

            if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
                $addr = inet_pton($ip);
                if (false !== $addr) {
                    $addr = substr($addr, 0, 6).str_repeat("\0", 10);
                    $anonymized = inet_ntop($addr);

                    return false !== $anonymized ? $anonymized : null;
                }
            }

            // Unrecognised format - do not echo it back, treat as unknown.
            return null;
        } catch (\Throwable) {
            return null;
        }
    }
}
