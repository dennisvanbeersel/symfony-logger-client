<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\DependencyInjection;

use ApplicationLogger\Bundle\DependencyInjection\ApplicationLoggerExtension;
use ApplicationLogger\Bundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
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
}
