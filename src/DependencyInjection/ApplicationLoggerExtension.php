<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * ApplicationLogger Extension.
 *
 * Configures services for the bundle with resilience as the top priority.
 * All services are designed to never throw exceptions or block the host application.
 */
class ApplicationLoggerExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        // Register bundle assets with AssetMapper
        $this->prependAssetMapper($container);
    }

    /**
     * Register bundle's JavaScript SDK assets with Symfony AssetMapper.
     */
    private function prependAssetMapper(ContainerBuilder $container): void
    {
        // Only register assets if framework bundle is loaded
        if (!$container->hasExtension('framework')) {
            return;
        }

        // Get the bundle directory (two levels up from this file)
        $bundleDir = \dirname(__DIR__, 2);

        // Register the assets/dist directory with @application-logger namespace
        $container->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [
                    $bundleDir.'/assets/dist' => '@application-logger',
                ],
            ],
        ]);
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Register all configuration as parameters for use in services.yaml
        $this->registerConfigurationParameters($container, $config);

        // Only register services if enabled
        if (!$config['enabled']) {
            return;
        }

        // Load services from YAML file
        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.yaml');
    }

    /**
     * Register configuration as container parameters.
     *
     * @param array<string, mixed> $config
     */
    private function registerConfigurationParameters(ContainerBuilder $container, array $config): void
    {
        // Flatten configuration into parameters
        $container->setParameter('application_logger.enabled', $config['enabled']);
        $container->setParameter('application_logger.dsn', $config['dsn']);
        $container->setParameter('application_logger.api_key', $config['api_key']);
        $container->setParameter('application_logger.timeout', $config['timeout']);
        $container->setParameter('application_logger.retry_attempts', $config['retry_attempts']);
        $container->setParameter('application_logger.async', $config['async']);
        $container->setParameter('application_logger.capture_level', $config['capture_level']);
        $container->setParameter('application_logger.release', $config['release']);
        $container->setParameter('application_logger.environment', $config['environment']);
        $container->setParameter('application_logger.scrub_fields', $config['scrub_fields']);
        $container->setParameter('application_logger.max_breadcrumbs', $config['max_breadcrumbs']);
        $container->setParameter('application_logger.debug', $config['debug']);

        // Circuit breaker parameters
        $container->setParameter('application_logger.circuit_breaker.enabled', $config['circuit_breaker']['enabled']);
        $container->setParameter('application_logger.circuit_breaker.failure_threshold', $config['circuit_breaker']['failure_threshold']);
        $container->setParameter('application_logger.circuit_breaker.timeout', $config['circuit_breaker']['timeout']);
        $container->setParameter('application_logger.circuit_breaker.half_open_attempts', $config['circuit_breaker']['half_open_attempts']);

        // Session tracking parameters
        $container->setParameter('application_logger.session_tracking.enabled', $config['session_tracking']['enabled']);
        $container->setParameter('application_logger.session_tracking.track_page_views', $config['session_tracking']['track_page_views']);
        $container->setParameter('application_logger.session_tracking.idle_timeout', $config['session_tracking']['idle_timeout']);
        $container->setParameter('application_logger.session_tracking.ignored_routes', $config['session_tracking']['ignored_routes']);
        $container->setParameter('application_logger.session_tracking.ignored_paths', $config['session_tracking']['ignored_paths']);

        // JavaScript SDK parameters
        $container->setParameter('application_logger.javascript.enabled', $config['javascript']['enabled']);
        $container->setParameter('application_logger.javascript.auto_inject', $config['javascript']['auto_inject']);
        $container->setParameter('application_logger.javascript.debug', $config['javascript']['debug']);
        // Environment defaults to root environment, which defaults to kernel.environment
        $container->setParameter('application_logger.javascript.environment', $config['javascript']['environment'] ?? $config['environment']);
        // Release defaults to root release config
        $container->setParameter('application_logger.javascript.release', $config['javascript']['release'] ?? $config['release']);
        // Merge root scrub_fields with javascript-specific scrub_fields
        $container->setParameter('application_logger.javascript.scrub_fields', array_unique(array_merge(
            $config['scrub_fields'],
            $config['javascript']['scrub_fields']
        )));
    }

    public function getAlias(): string
    {
        return 'application_logger';
    }
}
