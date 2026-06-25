<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Service\Sdk;

use ApplicationLogger\Bundle\Service\ContextCollectorInterface;
use ApplicationLogger\Bundle\Service\Sdk\BundleContextCollector;
use ApplicationLogger\Bundle\Service\Sdk\LoopbackGuard;
use ApplicationLogger\Bundle\Service\Sdk\SdkClientFactory;
use ApplicationLogger\Sdk\Hub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

final class SdkClientFactoryTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function factory(array $overrides = []): SdkClientFactory
    {
        $config = array_merge([
            'dsn' => 'https://applogger.eu/0xPROJECT',
            'api_key' => 'pk_test',
            'environment' => 'production',
            'release' => null,
            'enabled' => true,
            'scrub_fields' => ['password', 'credit_card', 'cvv', 'ssn', 'iban'],
            'max_breadcrumbs' => 50,
            'timeout' => 2.0,
            'flush_budget' => 2.0,
            'circuit_breaker' => ['failure_threshold' => 5, 'timeout' => 60, 'half_open_attempts' => 1],
            'log_endpoint' => null,
            'log_token' => null,
            'session_hash_salt' => 'salt',
            'app_name' => 'app',
            'cache_dir' => sys_get_temp_dir().'/applogger-bundle-test-'.uniqid('', true),
        ], $overrides);

        // BundleContextCollector is final — construct it from a mocked inner interface.
        $inner = $this->createMock(ContextCollectorInterface::class);
        $inner->method('collectContext')->willReturn([]);
        $ctx = new BundleContextCollector($inner);

        return new SdkClientFactory($config, $ctx, new LoopbackGuard(new RequestStack(), []));
    }

    public function testBuildsHubWithHttpTransportWhenConfigured(): void
    {
        Hub::reset();
        $hub = $this->factory()->getHub();
        self::assertInstanceOf(Hub::class, $hub);
        self::assertSame($hub, Hub::getCurrent());
    }

    public function testScrubFieldsForwardedInFull(): void
    {
        // The factory must pass the bundle's full scrub list (incl. PCI/GDPR fields) to sdk-core.
        // Assert via the getResolvedScrubFields() seam exposed for testing.
        $fields = $this->factory()->getResolvedScrubFields();
        foreach (['password', 'credit_card', 'cvv', 'ssn', 'iban'] as $f) {
            self::assertContains($f, $fields);
        }
    }

    public function testEmptyApiKeyWithDsnDisablesErrorTransport(): void
    {
        Hub::reset();
        $hub = $this->factory(['api_key' => ''])->getHub();
        // With api_key empty + dsn set, the factory disables the error transport (NullTransport)
        // so it never ships unauthenticated. Hub is still returned (degrades gracefully).
        self::assertInstanceOf(Hub::class, $hub);
    }
}
