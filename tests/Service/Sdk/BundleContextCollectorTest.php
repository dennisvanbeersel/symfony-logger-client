<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Service\Sdk;

use ApplicationLogger\Bundle\Service\ContextCollectorInterface;
use ApplicationLogger\Bundle\Service\Sdk\BundleContextCollector;
use PHPUnit\Framework\TestCase;

final class BundleContextCollectorTest extends TestCase
{
    public function testMapsBundleContextToSdkTopLevelKeys(): void
    {
        $bundle = $this->createMock(ContextCollectorInterface::class);
        $bundle->method('collectContext')->willReturn([
            'request' => [
                'url' => 'https://app.example/checkout',
                'method' => 'POST',
                'env' => ['REMOTE_ADDR' => '203.0.113.0', 'SERVER_NAME' => 'app.example'],
            ],
            'user' => ['session_id' => 'abc'],
            'server' => ['server_name' => 'web-01'],
            'environment' => 'production',
            'release' => 'v1.2.3',
            'session_hash' => str_repeat('a', 64),
        ]);

        $out = (new BundleContextCollector($bundle))->collect();

        self::assertSame('https://app.example/checkout', $out['url']);
        self::assertSame('POST', $out['http_method']);
        self::assertSame('203.0.113.0', $out['ip_address']);   // proxy-aware, anonymized by the bundle
        self::assertSame(str_repeat('a', 64), $out['session_hash']);
        self::assertSame('web-01', $out['server_name']);
        self::assertStringStartsWith('PHP ', $out['runtime']);
        self::assertArrayHasKey('request', $out);              // richer context preserved nested
    }

    public function testTotalOnFailure(): void
    {
        $bundle = $this->createMock(ContextCollectorInterface::class);
        $bundle->method('collectContext')->willThrowException(new \RuntimeException('boom'));
        self::assertSame([], (new BundleContextCollector($bundle))->collect());
    }
}
