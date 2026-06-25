<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Service\Sdk;

use ApplicationLogger\Bundle\Service\Sdk\LoopbackGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class LoopbackGuardTest extends TestCase
{
    private const PATHS = ['/api/v1/errors', '/api/v1/js-errors', '/api/v1/sessions', '/api/v1/logs', '/api/errors'];

    private function guard(?string $path): LoopbackGuard
    {
        $stack = new RequestStack();
        if (null !== $path) {
            $stack->push(Request::create($path));
        }

        return new LoopbackGuard($stack, self::PATHS);
    }

    public function testIngestPathIsLoopback(): void
    {
        self::assertTrue($this->guard('/api/v1/errors')->isIngestRequest());
        self::assertTrue($this->guard('/api/v1/logs/batch')->isIngestRequest());
        self::assertTrue($this->guard('/api/v1/sessions/123/events')->isIngestRequest());
    }

    public function testNonIngestPathPasses(): void
    {
        self::assertFalse($this->guard('/dashboard')->isIngestRequest());
        self::assertFalse($this->guard('/')->isIngestRequest());
    }

    public function testNoCurrentRequestFailsOpen(): void
    {
        self::assertFalse($this->guard(null)->isIngestRequest()); // CLI/no request -> not a web loopback
    }
}
