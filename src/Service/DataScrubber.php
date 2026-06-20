<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Service;

/**
 * Shared, stateless utility for GDPR-compliant data scrubbing and IP anonymization.
 *
 * Extracted so the Monolog handler, session tracking, and context collection all
 * apply the SAME, user-configurable scrub-field list and the SAME IP-masking logic.
 *
 * SCRUBBING BOUNDARY (read carefully - this is NOT general leak prevention):
 * Redaction is KEY-NAME based. {@see scrub()} only redacts a value when its KEY
 * (case-insensitively) contains a configured scrub fragment; values stored under
 * non-matching keys are passed through UNINSPECTED. A secret placed under an
 * innocuously named key (e.g. ['note' => 'my password is hunter2']) is NOT redacted.
 * The ONLY value-level redaction performed here is in {@see scrubUrl()} /
 * {@see scrubQueryString()}, which redact query-string PAIRS whose NAME matches a
 * scrub fragment (the URL query path/scheme/host are left intact) PLUS any embedded
 * userinfo credentials ("user:pass@host") in a URL's authority. Forced cookie-
 * header redaction lives in ContextCollector, not here. Do not rely on this class
 * to catch sensitive data hidden in free-form text or under arbitrary keys.
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
     * Redact sensitive VALUES from a raw query string.
     *
     * Splits the query into name=value pairs (preserving order and unknown shapes),
     * and replaces the value of any pair whose NAME matches a configured scrub
     * fragment (via {@see keyIsSensitive()}) with "[REDACTED]". So
     * "token=abc&page=2" becomes "token=[REDACTED]&page=2". Names/values are kept
     * URL-encoded as they appeared; only the redacted value is substituted verbatim.
     * Pairs without an "=" are preserved as-is.
     *
     * Returns the input unchanged for null; "" for empty. Never throws.
     */
    public function scrubQueryString(?string $qs): ?string
    {
        if (null === $qs) {
            return null;
        }
        if ('' === $qs) {
            return '';
        }

        try {
            $pairs = explode('&', $qs);
            foreach ($pairs as $i => $pair) {
                if ('' === $pair) {
                    continue;
                }

                $eqPos = strpos($pair, '=');
                if (false === $eqPos) {
                    // Bare key with no value (e.g. "?debug"); redact if sensitive.
                    if ($this->keyIsSensitive(rawurldecode($pair))) {
                        $pairs[$i] = $pair.'=[REDACTED]';
                    }
                    continue;
                }

                $rawName = substr($pair, 0, $eqPos);
                if ($this->keyIsSensitive(rawurldecode($rawName))) {
                    $pairs[$i] = $rawName.'=[REDACTED]';
                }
            }

            return implode('&', $pairs);
        } catch (\Throwable) {
            // Fail safe: redacting the whole query can never leak a sensitive value.
            return '[REDACTED]';
        }
    }

    /**
     * Redact sensitive VALUES from the query string of a URL, and ALWAYS redact
     * any embedded userinfo (credentials) in the authority component.
     *
     * The query component is inspected and run through {@see scrubQueryString()}.
     * In addition, a "user:password@host" (or "user@host") authority — legal in
     * fetch targets and occasionally seen in Referer/redirect chains — has its
     * userinfo segment replaced with "[REDACTED]@" so credentials never leave the
     * host application. Scheme, host, port, path and fragment are otherwise intact.
     * URLs with neither a query nor userinfo are returned unchanged.
     *
     * Returns the input unchanged for null; "" for empty. Never throws.
     */
    public function scrubUrl(?string $url): ?string
    {
        if (null === $url) {
            return null;
        }
        if ('' === $url) {
            return '';
        }

        try {
            // Redact embedded credentials (userinfo) FIRST so the rest of the
            // method operates on (and returns) a credential-free URL.
            $url = $this->scrubUrlUserinfo($url);

            $query = parse_url($url, \PHP_URL_QUERY);
            if (!\is_string($query) || '' === $query) {
                return $url;
            }

            $scrubbed = $this->scrubQueryString($query);
            if (null === $scrubbed || $scrubbed === $query) {
                return $url;
            }

            // Replace only the query segment, leaving any fragment intact.
            $hashPos = strpos($url, '#');
            $fragment = false !== $hashPos ? substr($url, $hashPos) : '';
            $beforeFragment = false !== $hashPos ? substr($url, 0, $hashPos) : $url;

            $qPos = strpos($beforeFragment, '?');
            if (false === $qPos) {
                return $url;
            }

            return substr($beforeFragment, 0, $qPos + 1).$scrubbed.$fragment;
        } catch (\Throwable) {
            // Fail safe: do not echo back a URL that may carry a sensitive value.
            return '[REDACTED]';
        }
    }

    /**
     * Replace any "//user:pass@host" / "//user@host" userinfo in a URL's authority
     * with "//[REDACTED]@host". The authority is the segment that follows either a
     * "scheme://" prefix OR a leading protocol-relative "//" (e.g.
     * "//user:pass@host/path"), so an "@" appearing later in the path/query/fragment
     * is left untouched. Returns the URL unchanged when no userinfo is present.
     */
    private function scrubUrlUserinfo(string $url): string
    {
        // Locate the start of the authority. A scheme-qualified URL marks it with
        // "://"; a protocol-relative URL (no scheme) starts the authority right
        // after a leading "//". Without the protocol-relative branch, a
        // "//user:pass@host" target would bypass redaction and leak credentials.
        $schemePos = strpos($url, '://');
        if (false !== $schemePos) {
            $authorityStart = $schemePos + \strlen('://');
        } elseif (str_starts_with($url, '//')) {
            $authorityStart = \strlen('//');
        } else {
            return $url;
        }

        // The authority ends at the first '/', '?' or '#' after "scheme://".
        $authorityEnd = \strlen($url);
        foreach (['/', '?', '#'] as $delimiter) {
            $pos = strpos($url, $delimiter, $authorityStart);
            if (false !== $pos && $pos < $authorityEnd) {
                $authorityEnd = $pos;
            }
        }

        $authority = substr($url, $authorityStart, $authorityEnd - $authorityStart);
        $atPos = strrpos($authority, '@');
        if (false === $atPos) {
            return $url;
        }

        $hostPart = substr($authority, $atPos + 1);

        return substr($url, 0, $authorityStart).'[REDACTED]@'.$hostPart.substr($url, $authorityEnd);
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
