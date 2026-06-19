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
}
