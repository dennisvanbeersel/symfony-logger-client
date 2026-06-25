<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\EventSubscriber;

use ApplicationLogger\Bundle\EventSubscriber\ScopeResetSubscriber;
use ApplicationLogger\Sdk\Client;
use ApplicationLogger\Sdk\Clock\FrozenClock;
use ApplicationLogger\Sdk\Context\ContextCollectorInterface as SdkCtx;
use ApplicationLogger\Sdk\DataScrubber;
use ApplicationLogger\Sdk\Hub;
use ApplicationLogger\Sdk\Options;
use ApplicationLogger\Sdk\Scope;
use ApplicationLogger\Sdk\StackTraceParser;
use ApplicationLogger\Sdk\Transport\FileTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ScopeResetSubscriberTest extends TestCase
{
    protected function tearDown(): void
    {
        Hub::reset();
    }

    public function testRequestResetsScopeSoWorkerRequestsDoNotBleed(): void
    {
        $path = sys_get_temp_dir().'/applogger_scope_'.uniqid('', true).'.ndjson';
        $nullCtx = new class implements SdkCtx {
            public function collect(): array
            {
                return [];
            }
        };
        $opts = Options::fromArray(['dsn' => 'https://applogger.eu/0xP']);
        $client = new Client($opts, new FileTransport($path), new FrozenClock(new \DateTimeImmutable('2026-01-01')), new DataScrubber([]), new StackTraceParser(), $nullCtx);
        $hub = new Hub($client, new Scope());
        Hub::setCurrent($hub);
        $hub->getScope()->setUser(['id' => 'A']);                     // request N

        $sub = new ScopeResetSubscriber();
        $sub->onKernelRequest(new RequestEvent($this->createMock(HttpKernelInterface::class), Request::create('/x'), HttpKernelInterface::MAIN_REQUEST));   // request N+1 -> reset

        $hub->captureException(new \RuntimeException('boom'));         // no user set this request
        $client->flush();
        $events = (new FileTransport($path))->capturedEvents();
        @unlink($path);

        self::assertArrayNotHasKey('user', $events[0]['context'] ?? [], 'scope must be reset between worker requests');
    }
}
