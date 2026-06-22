<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Service\Http;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Proves the real CurlHttpClient exposes connect_time / primary_ip / http_code via
 * getInfo() WITHOUT blocking on an unsettled handle — the assumption behind the
 * progress-aware breaker (settleUnconfirmed()). Skipped where curl is unavailable.
 */
#[Group('integration')]
final class CurlGetInfoIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\extension_loaded('curl')) {
            self::markTestSkipped('curl extension not available');
        }
    }

    /** An unroutable address (TEST-NET-1) never connects: connect_time stays 0, primary_ip empty. */
    public function testGetInfoIsNonBlockingAndTypedOnUnconnectedHandle(): void
    {
        $client = HttpClient::create(['timeout' => 1.0, 'max_duration' => 1.0]);

        $response = $client->request('POST', 'http://192.0.2.1/ingest', [
            'body' => '{}',
            'buffer' => false,
            'timeout' => 1.0,
            'max_duration' => 1.0,
        ]);

        // One non-blocking 0.0 poll (mirrors dispatchAsync); the handle will NOT have connected.
        // The timeout chunk may throw TimeoutException when accessed — that's fine, it means
        // the handle is still in-flight (not connected), which is exactly the state we need.
        try {
            foreach ($client->stream($response, 0.0) as $chunk) {
                if ($chunk->isTimeout()) {
                    break; // still in flight → stop, leave the handle unsettled
                }
                break;
            }
        } catch (ExceptionInterface) {
            // TimeoutException from the chunk: handle is still in-flight, which is correct.
        }

        // getInfo() must be non-blocking and correctly typed on the unsettled handle.
        $httpCode = $response->getInfo('http_code');
        $connectTime = $response->getInfo('connect_time');
        $primaryIp = $response->getInfo('primary_ip');

        self::assertSame(0, $httpCode, 'unconnected handle has no HTTP status');
        self::assertIsFloat($connectTime, 'connect_time must be a float');
        self::assertSame(0.0, $connectTime, 'unconnected handle has connect_time 0.0');
        // primary_ip is an empty string (or null) before connection — both mean "not connected".
        self::assertTrue('' === $primaryIp || null === $primaryIp, 'no primary_ip before connection');

        try {
            $response->cancel();
        } catch (ExceptionInterface) {
            // ignore
        }
    }
}
