<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Service;

use ApplicationLogger\Bundle\Service\DataScrubber;
use PHPUnit\Framework\TestCase;

/**
 * Covers the URL / query-string value scrubbing added for C1.
 *
 * These are the only VALUE-level redactions DataScrubber performs: query pairs
 * whose NAME matches a configured scrub fragment are redacted while the rest of
 * the URL is preserved.
 */
final class DataScrubberTest extends TestCase
{
    private function scrubber(): DataScrubber
    {
        return new DataScrubber(['password', 'token', 'api_key', 'secret', 'authorization', 'card_number']);
    }

    public function testScrubQueryStringRedactsSensitivePairKeepsOthers(): void
    {
        $result = $this->scrubber()->scrubQueryString('token=secret&page=2');

        $this->assertSame('token=[REDACTED]&page=2', $result);
    }

    public function testScrubQueryStringRedactsMultipleSensitivePairs(): void
    {
        $result = $this->scrubber()->scrubQueryString('password=x&q=ok&card_number=4111111111111111');

        $this->assertSame('password=[REDACTED]&q=ok&card_number=[REDACTED]', $result);
    }

    public function testScrubQueryStringPreservesOrderAndUnknownShapes(): void
    {
        // Bare flag (no '='), then a sensitive pair, then a normal pair.
        $result = $this->scrubber()->scrubQueryString('debug&token=abc&page=2');

        $this->assertSame('debug&token=[REDACTED]&page=2', $result);
    }

    public function testScrubQueryStringHandlesNullAndEmpty(): void
    {
        $this->assertNull($this->scrubber()->scrubQueryString(null));
        $this->assertSame('', $this->scrubber()->scrubQueryString(''));
    }

    public function testScrubUrlRedactsQueryValueKeepsHostAndPath(): void
    {
        $result = $this->scrubber()->scrubUrl('https://h/p?password=x&q=ok');

        $this->assertSame('https://h/p?password=[REDACTED]&q=ok', $result);
    }

    public function testScrubUrlPreservesSchemeHostPathPortAndFragment(): void
    {
        $result = $this->scrubber()->scrubUrl('https://example.com:8443/reset?token=abc&next=/home#section');

        $this->assertSame('https://example.com:8443/reset?token=[REDACTED]&next=/home#section', $result);
    }

    public function testScrubUrlWithoutQueryReturnedUnchanged(): void
    {
        $url = 'https://example.com/path/segment';
        $this->assertSame($url, $this->scrubber()->scrubUrl($url));
    }

    public function testScrubUrlHandlesNullAndEmpty(): void
    {
        $this->assertNull($this->scrubber()->scrubUrl(null));
        $this->assertSame('', $this->scrubber()->scrubUrl(''));
    }

    public function testScrubUrlDoesNotRedactPathSegments(): void
    {
        // Path segments are explicitly out of scope; only the query is scrubbed.
        $url = 'https://example.com/token/abc?page=2';
        $this->assertSame($url, $this->scrubber()->scrubUrl($url));
    }

    public function testScrubMatchIsCaseInsensitive(): void
    {
        $result = $this->scrubber()->scrubQueryString('Token=abc&API_KEY=xyz');

        $this->assertSame('Token=[REDACTED]&API_KEY=[REDACTED]', $result);
    }

    public function testScrubUrlRedactsUserinfoCredentials(): void
    {
        $result = $this->scrubber()->scrubUrl('https://admin:s3cret@internal.api/path');

        $this->assertSame('https://[REDACTED]@internal.api/path', $result);
    }

    public function testScrubUrlRedactsUserinfoWithoutPassword(): void
    {
        $result = $this->scrubber()->scrubUrl('https://admin@internal.api/path');

        $this->assertSame('https://[REDACTED]@internal.api/path', $result);
    }

    public function testScrubUrlRedactsUserinfoAndQueryTogether(): void
    {
        $result = $this->scrubber()->scrubUrl('https://user:pass@h:8443/p?token=abc&q=ok#frag');

        $this->assertSame('https://[REDACTED]@h:8443/p?token=[REDACTED]&q=ok#frag', $result);
    }

    public function testScrubUrlDoesNotTreatAtInPathOrQueryAsUserinfo(): void
    {
        // An '@' in the path or query must not be mistaken for an authority delimiter.
        $url = 'https://example.com/users/a@b.com?to=x@y.com';
        $this->assertSame($url, $this->scrubber()->scrubUrl($url));
    }

    public function testScrubUrlRedactsUserinfoWithoutPathOrQuery(): void
    {
        $result = $this->scrubber()->scrubUrl('https://admin:s3cret@internal.api');

        $this->assertSame('https://[REDACTED]@internal.api', $result);
    }

    public function testScrubUrlRedactsUserinfoInProtocolRelativeUrl(): void
    {
        // A scheme-less, protocol-relative URL ("//user:pass@host/path") must still
        // have its embedded credentials redacted. Before the leading-"//" branch was
        // added, this bypassed authority detection and leaked the password verbatim.
        $result = $this->scrubber()->scrubUrl('//admin:s3cret@internal.api/path');

        $this->assertSame('//[REDACTED]@internal.api/path', $result);
    }

    public function testScrubUrlRedactsUserinfoAndQueryInProtocolRelativeUrl(): void
    {
        $result = $this->scrubber()->scrubUrl('//user:pass@h:8443/p?token=abc&q=ok#frag');

        $this->assertSame('//[REDACTED]@h:8443/p?token=[REDACTED]&q=ok#frag', $result);
    }

    // -------------------------------------------------------------------------
    // BUNDLE-3: optimized scrub() preserves EXACT redaction semantics.
    // -------------------------------------------------------------------------

    /**
     * Reference implementation of the ORIGINAL key-match semantics:
     * a key is sensitive if stripos($key, $field) !== false for any non-empty $field
     * (case-insensitive ASCII substring). The optimized DataScrubber must match this
     * byte-for-byte on every key.
     *
     * @param list<string> $scrubFields
     */
    private function referenceKeyIsSensitive(string $key, array $scrubFields): bool
    {
        foreach ($scrubFields as $field) {
            if ('' !== $field && false !== stripos($key, $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply the reference (pre-optimization) recursive scrub.
     *
     * @param array<array-key, mixed> $data
     * @param list<string> $scrubFields
     *
     * @return array<array-key, mixed>
     */
    private function referenceScrub(array $data, array $scrubFields, int $depth = 16): array
    {
        if ($depth <= 0) {
            return [];
        }

        $out = [];
        foreach ($data as $key => $value) {
            if ($this->referenceKeyIsSensitive((string) $key, $scrubFields)) {
                $out[$key] = '[REDACTED]';
                continue;
            }
            $out[$key] = \is_array($value)
                ? $this->referenceScrub($value, $scrubFields, $depth - 1)
                : $value;
        }

        return $out;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function representativePayload(): array
    {
        return [
            // Plain, untouched keys.
            'username' => 'john',
            'email' => 'john@example.com',
            // Exact + mixed-case matches.
            'password' => 'hunter2',
            'PASSWORD' => 'HUNTER2',
            'Api_Key' => 'sk_live_123',
            'authorization' => 'Bearer abc',
            // Substring matches (key CONTAINS a fragment).
            'user_token' => 'tok',
            'X-Secret-Header' => 'shh',
            'reset_password_url' => 'https://x',
            // Numeric + non-string keys.
            0 => 'zero',
            42 => 'forty-two',
            // Nested arrays (recursion).
            'user' => [
                'name' => 'jane',
                'Token' => 'nested-tok',
                'profile' => [
                    'api_secret' => 'deep',
                    'bio' => 'hello',
                ],
            ],
            // Empty values + mixed scalar types preserved verbatim.
            'count' => 7,
            'active' => true,
            'nothing' => null,
            'items' => ['a', 'b', 'c'],
            // Keys that merely look similar but do not contain a fragment.
            'tokenless' => 'keepme', // contains 'token' -> WILL be redacted (substring)
            'pass' => 'keepme-too',  // does NOT contain 'password' -> kept
        ];
    }

    public function testOptimizedScrubMatchesReferenceOnRepresentativePayload(): void
    {
        $scrubFields = ['password', 'token', 'api_key', 'secret', 'authorization', 'card_number'];
        $scrubber = new DataScrubber($scrubFields);

        $payload = $this->representativePayload();

        $this->assertSame(
            $this->referenceScrub($payload, $scrubFields),
            $scrubber->scrub($payload),
            'optimized scrub() must produce byte-identical redaction to the original per-pair stripos logic',
        );
    }

    public function testOptimizedScrubMatchesReferenceWithMixedCaseScrubFields(): void
    {
        // Scrub fields supplied in mixed case must behave identically (the optimization
        // lowercases fragments once at construction; matching stays case-insensitive).
        $scrubFields = ['PassWord', 'TOKEN', 'Api_Key', '', 'SECRET'];
        $scrubber = new DataScrubber($scrubFields);

        $payload = $this->representativePayload();

        $this->assertSame(
            $this->referenceScrub($payload, $scrubFields),
            $scrubber->scrub($payload),
        );
    }

    public function testRedactedKeysAreExactlyAsExpected(): void
    {
        // Pin the concrete expected output so a future regression in either the
        // optimized impl OR the reference is caught explicitly.
        $scrubber = new DataScrubber(['password', 'token', 'secret']);

        $result = $scrubber->scrub([
            'password' => 'x',
            'Token' => 'y',
            'user_secret' => 'z',
            'pass' => 'keep',
            'note' => 'plain',
        ]);

        $this->assertSame([
            'password' => '[REDACTED]',
            'Token' => '[REDACTED]',
            'user_secret' => '[REDACTED]',
            'pass' => 'keep',
            'note' => 'plain',
        ], $result);
    }
}
