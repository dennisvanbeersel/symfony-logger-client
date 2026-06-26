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

        // Auto-wire the Monolog handler so a clean install captures logs with no
        // manual monolog.yaml edits (zero-config).
        $this->prependMonolog($container);
    }

    /**
     * Compute the Monolog handler channel denylist. The mandatory set is always
     * excluded (event/request/php carry uncaught-exception logs already shipped by
     * ExceptionSubscriber; application_logger_internal is the bundle's own diagnostics
     * sink). User-configured channel names are appended `!`-prefixed and deduped.
     *
     * @param list<string> $excluded channel NAMES (no leading `!`)
     *
     * @return list<string>
     */
    public static function buildChannelList(array $excluded): array
    {
        $mandatory = ['!event', '!request', '!php', '!application_logger_internal'];
        $user = array_map(static fn (string $c): string => '!'.ltrim($c, '!'), $excluded);

        return array_values(array_unique([...$mandatory, ...$user]));
    }

    /**
     * Self-wire the bundle's Monolog handler into the host's Monolog configuration.
     *
     * This is NOT gated on `enabled`: that value may be an unresolved env placeholder
     * (`%env(bool:...)%`) at compile time. Instead the handler itself no-ops at runtime
     * when `application_logger.enabled` is false (see ApplicationLoggerHandler::write()).
     */
    private function prependMonolog(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('monolog')) {
            return;
        }

        // Read ONLY excluded_channels from the raw merged config. Do NOT call
        // processConfiguration() here: at prepend time the env-placeholder machinery
        // is not yet installed, so processing the full tree throws on the env-driven
        // `enabled` (%env(bool:...)%) placeholder and breaks the host's cache:clear.
        $raw = array_merge(...array_values($container->getExtensionConfig($this->getAlias())) ?: [[]]);
        $excluded = (isset($raw['excluded_channels']) && \is_array($raw['excluded_channels']))
            ? array_values(array_filter($raw['excluded_channels'], '\is_string'))
            : ['http_client', 'console', 'deprecation', 'doctrine'];

        $container->prependExtensionConfig('monolog', [
            // Dedicated channel for the bundle's OWN diagnostics; always excluded from
            // the handler (below) so the bundle can never self-report into its pipeline.
            'channels' => ['application_logger_internal'],
            'handlers' => [
                'application_logger' => [
                    'type' => 'service',
                    'id' => \ApplicationLogger\Bundle\Monolog\Handler\ApplicationLoggerHandler::class,
                    'channels' => self::buildChannelList($excluded),
                ],
            ],
        ]);
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

        // symfony/asset-mapper is require-dev only. When it is absent, prepending
        // framework.asset_mapper.paths makes FrameworkExtension throw a LogicException
        // at compile time, breaking the host container. Guard on the component's
        // availability (mirrors the hasExtension('monolog') guard in prependMonolog).
        if (!interface_exists(\Symfony\Component\AssetMapper\AssetMapperInterface::class)) {
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

        // Services are ALWAYS loaded, even when `enabled` is false. The compile-time
        // value of `enabled` may be an unresolved env placeholder (`%env(bool:...)%`),
        // and prependMonolog() registers a Monolog handler that references the
        // ApplicationLoggerHandler service — gating the service load on `enabled` here
        // would leave that reference dangling and break the container. The real gate is
        // the RUNTIME kill-switch (sdk-core transport, the Monolog handler write(), and
        // the Twig extension), which makes a disabled bundle fully inert.
        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.yaml');

        // The console command is optional: only register it when symfony/console is
        // installed in the host app (this is a redistributable bundle).
        if (class_exists(\Symfony\Component\Console\Command\Command::class)) {
            $loader->load('commands.yaml');
        }
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
        // World-readable browser key. SEPARATE from the secret api_key above — this
        // is the ONLY credential that may reach Twig/buildConfig()/the inline HTML.
        $container->setParameter('application_logger.publishable_key', $config['publishable_key']);
        $container->setParameter('application_logger.timeout', $config['timeout']);
        $container->setParameter('application_logger.flush_budget', $config['flush_budget']);
        $container->setParameter('application_logger.retry_attempts', $config['retry_attempts']);
        $container->setParameter('application_logger.async', $config['async']);
        $container->setParameter('application_logger.capture_level', $config['capture_level']);
        $container->setParameter('application_logger.release', $config['release']);
        $container->setParameter('application_logger.environment', $config['environment']);
        $container->setParameter('application_logger.scrub_fields', $config['scrub_fields']);
        $container->setParameter('application_logger.max_breadcrumbs', $config['max_breadcrumbs']);
        $container->setParameter('application_logger.debug', $config['debug']);

        // Error/log endpoint routing parameters
        $container->setParameter('application_logger.endpoint_path', $config['endpoint_path']);
        // Normalize empty-string -> null so log aggregation cleanly no-ops when wired to an
        // unset %env()% (env placeholders resolve to '' not null, and a present-but-empty
        // config key does NOT pick up defaultNull()). Without this, sendLogs() would build a
        // malformed URL from '' and penalise the log circuit breaker instead of no-opping.
        $container->setParameter('application_logger.log_endpoint', ('' !== (string) $config['log_endpoint']) ? $config['log_endpoint'] : null);
        $container->setParameter('application_logger.log_token', ('' !== (string) $config['log_token']) ? $config['log_token'] : null);
        $container->setParameter('application_logger.log_path', $config['log_path']);
        $container->setParameter('application_logger.log_batch_size', $config['log_batch_size']);
        $container->setParameter('application_logger.max_log_buffer', $config['max_log_buffer']);
        $container->setParameter('application_logger.error_tracking_enabled', $config['error_tracking_enabled']);
        $container->setParameter('application_logger.log_aggregation_enabled', $config['log_aggregation_enabled']);
        $container->setParameter('application_logger.excluded_channels', $config['excluded_channels']);

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

        // Session replay parameters (consumed by the JS SDK via the Twig extension)
        $container->setParameter('application_logger.session_replay.enabled', $config['session_replay']['enabled']);
        $container->setParameter('application_logger.session_replay.buffer_before_error_seconds', $config['session_replay']['buffer_before_error_seconds']);
        $container->setParameter('application_logger.session_replay.buffer_before_error_clicks', $config['session_replay']['buffer_before_error_clicks']);
        $container->setParameter('application_logger.session_replay.buffer_after_error_seconds', $config['session_replay']['buffer_after_error_seconds']);
        $container->setParameter('application_logger.session_replay.buffer_after_error_clicks', $config['session_replay']['buffer_after_error_clicks']);
        $container->setParameter('application_logger.session_replay.click_debounce_ms', $config['session_replay']['click_debounce_ms']);
        $container->setParameter('application_logger.session_replay.snapshot_throttle_ms', $config['session_replay']['snapshot_throttle_ms']);
        $container->setParameter('application_logger.session_replay.max_snapshot_size', $config['session_replay']['max_snapshot_size']);
        $container->setParameter('application_logger.session_replay.session_timeout_minutes', $config['session_replay']['session_timeout_minutes']);
        $container->setParameter('application_logger.session_replay.max_buffer_size_mb', $config['session_replay']['max_buffer_size_mb']);
        $container->setParameter('application_logger.session_replay.expose_api', $config['session_replay']['expose_api']);

        // SDK-core wiring parameters
        $container->setParameter('application_logger.loopback_paths', $config['loopback_paths']);
        $container->setParameter('application_logger.session_hash_salt', $config['session_hash_salt']);

        // Assembled sdk_config passed to SdkClientFactory. Keys = exactly what
        // SdkClientFactory::build() reads (verified against lines ~61-106).
        // cache_dir and app_name are sourced from kernel params (not bundle config).
        // Defensive fallbacks for test containers that omit kernel.* params.
        $cacheDir = $container->hasParameter('kernel.cache_dir')
            ? (string) $container->getParameter('kernel.cache_dir')
            : sys_get_temp_dir().'/applogger';
        $appName = $container->hasParameter('kernel.project_dir')
            ? basename((string) $container->getParameter('kernel.project_dir'))
            : 'app';
        $container->setParameter('application_logger.sdk_config', [
            'dsn' => $config['dsn'],
            'api_key' => $config['api_key'],
            'enabled' => $config['enabled'],
            'scrub_fields' => $config['scrub_fields'], // FULL resolved bundle list — do not narrow
            // sdk-core's circuit breaker is always-on (no enable toggle); the legacy
            // `circuit_breaker.enabled` config still gates the deprecated ApiClient path
            // until Task 11 retires it, so the node stays live — it is just not forwarded here.
            'circuit_breaker' => [
                'failure_threshold' => $config['circuit_breaker']['failure_threshold'],
                'timeout' => $config['circuit_breaker']['timeout'],
                'half_open_attempts' => $config['circuit_breaker']['half_open_attempts'],
            ],
            'environment' => $config['environment'],
            'release' => $config['release'],
            'max_breadcrumbs' => $config['max_breadcrumbs'],
            'timeout' => $config['timeout'],
            'flush_budget' => $config['flush_budget'],
            'session_hash_salt' => $config['session_hash_salt'],
            'cache_dir' => $cacheDir,
            'log_endpoint' => ('' !== (string) $config['log_endpoint']) ? $config['log_endpoint'] : null,
            'log_token' => ('' !== (string) $config['log_token']) ? $config['log_token'] : null,
            'app_name' => $appName,
        ]);

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
