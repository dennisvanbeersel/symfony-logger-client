<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\DependencyInjection;

use ApplicationLogger\Bundle\DependencyInjection\ApplicationLoggerExtension;
use ApplicationLogger\Bundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\MonologBundle\DependencyInjection\MonologExtension;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Guards the DI parameter wiring. The bundle's service tests construct services
 * directly (bypassing the container), so a config option referenced from
 * services.yaml as `%application_logger.<key>%` but never registered as a container
 * parameter only fails when the bundle is loaded into a real kernel — which is
 * exactly how the missing `application_logger.flush_budget` parameter slipped past
 * the unit suite and surfaced as a "non-existent parameter" error at the platform's
 * cache:clear.
 *
 * We exercise the private parameter-flattening directly (via reflection) rather than
 * the full load(), because load() pulls in services.yaml via YamlFileLoader and the
 * bundle's test environment does not ship symfony/yaml.
 */
final class ApplicationLoggerExtensionTest extends TestCase
{
    private function registerParameters(ContainerBuilder $container): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), []);
        $method = new \ReflectionMethod(ApplicationLoggerExtension::class, 'registerConfigurationParameters');
        $method->invoke(new ApplicationLoggerExtension(), $container, $config);
    }

    public function testRegistersFlushBudgetParameterWithDefault(): void
    {
        $container = new ContainerBuilder();
        $this->registerParameters($container);

        self::assertTrue(
            $container->hasParameter('application_logger.flush_budget'),
            'services.yaml references %application_logger.flush_budget%, so the extension MUST register it',
        );
        self::assertSame(2.0, $container->getParameter('application_logger.flush_budget'));
    }

    public function testBuildChannelListWithDefaults(): void
    {
        self::assertSame(
            ['!event', '!request', '!php', '!application_logger_internal', '!http_client', '!console', '!deprecation', '!doctrine'],
            ApplicationLoggerExtension::buildChannelList(['http_client', 'console', 'deprecation', 'doctrine']),
        );
    }

    public function testBuildChannelListEmptyExcludedYieldsMandatoryOnly(): void
    {
        self::assertSame(
            ['!event', '!request', '!php', '!application_logger_internal'],
            ApplicationLoggerExtension::buildChannelList([]),
        );
    }

    public function testBuildChannelListStripsBangAndDedupes(): void
    {
        // A user who writes "!redis" or duplicates "event" must not produce "!!redis" or a dupe.
        self::assertSame(
            ['!event', '!request', '!php', '!application_logger_internal', '!redis'],
            ApplicationLoggerExtension::buildChannelList(['!redis', 'event']),
        );
    }

    public function testExcludedChannelsParameterIsRegistered(): void
    {
        $container = new ContainerBuilder();
        $config = (new Processor())->processConfiguration(new Configuration(), []);
        $method = new \ReflectionMethod(ApplicationLoggerExtension::class, 'registerConfigurationParameters');
        $method->invoke(new ApplicationLoggerExtension(), $container, $config);

        self::assertTrue($container->hasParameter('application_logger.excluded_channels'));
        self::assertSame(['http_client', 'console', 'deprecation', 'doctrine'], $container->getParameter('application_logger.excluded_channels'));
    }

    public function testPrependMonologSetsDefaultChannelDenylistAndInternalChannel(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new ApplicationLoggerExtension());
        $container->registerExtension(new MonologExtension());

        (new ApplicationLoggerExtension())->prepend($container);

        $monolog = array_merge(...array_values($container->getExtensionConfig('monolog')) ?: [[]]);

        self::assertSame(
            ['!event', '!request', '!php', '!application_logger_internal', '!http_client', '!console', '!deprecation', '!doctrine'],
            $monolog['handlers']['application_logger']['channels'],
        );
        self::assertContains('application_logger_internal', $monolog['channels'] ?? []);
    }

    public function testPrependMonologHonorsCustomExcludedChannels(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new ApplicationLoggerExtension());
        $container->registerExtension(new MonologExtension());
        $container->prependExtensionConfig('application_logger', ['excluded_channels' => ['redis']]);

        (new ApplicationLoggerExtension())->prepend($container);

        $monolog = array_merge(...array_values($container->getExtensionConfig('monolog')) ?: [[]]);

        self::assertSame(
            ['!event', '!request', '!php', '!application_logger_internal', '!redis'],
            $monolog['handlers']['application_logger']['channels'],
        );
    }

    public function testToggleParametersAreRegistered(): void
    {
        $container = new ContainerBuilder();
        $config = (new Processor())->processConfiguration(new Configuration(), []);
        $method = new \ReflectionMethod(ApplicationLoggerExtension::class, 'registerConfigurationParameters');
        $method->invoke(new ApplicationLoggerExtension(), $container, $config);

        self::assertTrue($container->getParameter('application_logger.error_tracking_enabled'));
        self::assertTrue($container->getParameter('application_logger.log_aggregation_enabled'));
    }

    public function testLoopbackPathsParameterIsRegistered(): void
    {
        $container = new ContainerBuilder();
        $this->registerParameters($container);

        self::assertTrue($container->hasParameter('application_logger.loopback_paths'));
        self::assertSame(
            ['/api/v1/errors', '/api/v1/js-errors', '/api/v1/sessions', '/api/v1/logs', '/api/errors'],
            $container->getParameter('application_logger.loopback_paths'),
        );
    }

    public function testSessionHashSaltParameterIsRegistered(): void
    {
        $container = new ContainerBuilder();
        $this->registerParameters($container);

        self::assertTrue($container->hasParameter('application_logger.session_hash_salt'));
        self::assertNull($container->getParameter('application_logger.session_hash_salt'));
    }

    /**
     * Every `%application_logger.*%` placeholder referenced in services.yaml must
     * resolve to a registered parameter. This cross-check catches a future config
     * option wired into services.yaml without a matching setParameter() — the exact
     * class of bug that broke the platform container compile.
     */
    public function testEveryServicesYamlParameterReferenceIsRegistered(): void
    {
        $container = new ContainerBuilder();
        $this->registerParameters($container);

        $servicesYaml = (string) file_get_contents(\dirname(__DIR__, 2).'/config/services.yaml');
        preg_match_all('/%(application_logger\.[a-z0-9_.]+)%/i', $servicesYaml, $matches);

        $referenced = array_values(array_unique($matches[1]));
        self::assertNotEmpty($referenced, 'sanity: services.yaml should reference application_logger.* parameters');

        $missing = array_values(array_filter(
            $referenced,
            static fn (string $param): bool => !$container->hasParameter($param),
        ));

        self::assertSame([], $missing, 'services.yaml references parameters the extension never registers: '.implode(', ', $missing));
    }

    public function testPublishableKeyParameterIsRegistered(): void
    {
        $container = new ContainerBuilder();
        $config = (new Processor())->processConfiguration(new Configuration(), [['publishable_key' => 'pk_test_deadbeef']]);
        $method = new \ReflectionMethod(ApplicationLoggerExtension::class, 'registerConfigurationParameters');
        $method->invoke(new ApplicationLoggerExtension(), $container, $config);

        self::assertTrue($container->hasParameter('application_logger.publishable_key'));
        self::assertSame('pk_test_deadbeef', $container->getParameter('application_logger.publishable_key'));
    }

    public function testServerApiKeyParameterIsUntouched(): void
    {
        $container = new ContainerBuilder();
        $config = (new Processor())->processConfiguration(new Configuration(), [['api_key' => 'sk_secret', 'publishable_key' => 'pk_test_x']]);
        $method = new \ReflectionMethod(ApplicationLoggerExtension::class, 'registerConfigurationParameters');
        $method->invoke(new ApplicationLoggerExtension(), $container, $config);

        self::assertSame('sk_secret', $container->getParameter('application_logger.api_key'));

        // The server sdk_config still carries the secret api_key, unchanged.
        $sdkConfig = $container->getParameter('application_logger.sdk_config');
        self::assertSame('sk_secret', $sdkConfig['api_key']);
        self::assertArrayNotHasKey('publishable_key', $sdkConfig);
    }
}
