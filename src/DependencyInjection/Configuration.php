<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration schema for ApplicationLogger bundle.
 *
 * Defines all available configuration options with secure defaults.
 * Focus on resilience: short timeouts, circuit breaker enabled by default.
 */
class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('application_logger');
        $rootNode = $treeBuilder->getRootNode();

        // @phpstan-ignore-next-line
        $rootNode
            ->children()
                // Core configuration
                ->scalarNode('dsn')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info('Data Source Name - Project endpoint URL (format: https://host/project-id)')
                ->end()
                ->scalarNode('api_key')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info('API Key for authentication (sent as X-Api-Key header)')
                ->end()
                ->booleanNode('enabled')
                    ->defaultTrue()
                    ->info('Enable/disable error tracking globally')
                ->end()
                ->scalarNode('release')
                    ->defaultNull()
                    ->info('Application version/release identifier')
                ->end()
                ->scalarNode('environment')
                    ->defaultValue('production')
                    ->info('Environment name (production, staging, development)')
                ->end()

                // Performance & Resilience
                ->floatNode('timeout')
                    ->defaultValue(2.0)
                    ->min(0.5)
                    ->max(5.0)
                    ->info('Maximum timeout for API requests in seconds (default: 2s for resilience)')
                ->end()
                ->integerNode('retry_attempts')
                    ->defaultValue(0)
                    ->min(0)
                    ->max(3)
                    ->info('Number of retry attempts (0 = fail fast, recommended for resilience)')
                ->end()

                // Circuit Breaker Configuration
                ->arrayNode('circuit_breaker')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('Enable circuit breaker pattern to prevent cascade failures')
                        ->end()
                        ->integerNode('failure_threshold')
                            ->defaultValue(5)
                            ->min(1)
                            ->info('Number of consecutive failures before opening circuit')
                        ->end()
                        ->integerNode('timeout')
                            ->defaultValue(60)
                            ->min(10)
                            ->info('Time in seconds to keep circuit open after opening')
                        ->end()
                        ->integerNode('half_open_attempts')
                            ->defaultValue(1)
                            ->min(1)
                            ->info('Number of test requests allowed in half-open state')
                        ->end()
                    ->end()
                ->end()

                // Capture Settings
                ->scalarNode('capture_level')
                    ->defaultValue('error')
                    ->info('Minimum Monolog level to capture (debug, info, notice, warning, error, critical, alert, emergency)')
                ->end()
                ->arrayNode('scrub_fields')
                    ->prototype('scalar')->end()
                    ->defaultValue(['password', 'token', 'api_key', 'secret', 'authorization'])
                    ->info('Field names to scrub from requests (security)')
                ->end()
                ->integerNode('max_breadcrumbs')
                    ->defaultValue(50)
                    ->min(10)
                    ->max(100)
                    ->info('Maximum number of breadcrumbs to track')
                ->end()

                // JavaScript SDK Configuration
                ->arrayNode('javascript')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('Enable JavaScript SDK integration and error tracking')
                        ->end()
                        ->booleanNode('auto_inject')
                            ->defaultTrue()
                            ->info('Automatically inject JavaScript SDK on all HTML pages')
                        ->end()
                        ->booleanNode('debug')
                            ->defaultFalse()
                            ->info('Enable JavaScript SDK debug mode (console.log)')
                        ->end()
                        ->scalarNode('environment')
                            ->defaultNull()
                            ->info('Environment name for JavaScript errors (defaults to kernel.environment)')
                        ->end()
                        ->scalarNode('release')
                            ->defaultNull()
                            ->info('Release version for JavaScript errors (defaults to root release config)')
                        ->end()
                        ->arrayNode('scrub_fields')
                            ->prototype('scalar')->end()
                            ->defaultValue([])
                            ->info('Additional fields to scrub in JavaScript errors (merged with root scrub_fields)')
                        ->end()
                    ->end()
                ->end()

                // Session Tracking Configuration
                ->arrayNode('session_tracking')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('Enable automatic session tracking (required for heatmap)')
                        ->end()
                        ->booleanNode('track_page_views')
                            ->defaultTrue()
                            ->info('Automatically track page views as session events')
                        ->end()
                        ->integerNode('idle_timeout')
                            ->defaultValue(1800)
                            ->min(300)
                            ->max(7200)
                            ->info('Session idle timeout in seconds (default: 30 minutes)')
                        ->end()
                        ->arrayNode('ignored_routes')
                            ->prototype('scalar')->end()
                            ->defaultValue(['_profiler', '_wdt'])
                            ->info('Route names to ignore for session tracking')
                        ->end()
                        ->arrayNode('ignored_paths')
                            ->prototype('scalar')->end()
                            ->defaultValue(['/api/', '/_fragment'])
                            ->info('URL paths to ignore for session tracking (prefix match)')
                        ->end()
                    ->end()
                ->end()

                // Heatmap Tracking Configuration
                ->arrayNode('heatmap')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('Enable heatmap click tracking (requires session_tracking.enabled)')
                        ->end()
                        ->integerNode('batch_size')
                            ->defaultValue(10)
                            ->min(1)
                            ->max(50)
                            ->info('Number of clicks to batch before sending to API')
                        ->end()
                        ->integerNode('batch_timeout')
                            ->defaultValue(5000)
                            ->min(1000)
                            ->max(30000)
                            ->info('Maximum time in milliseconds to wait before sending batch')
                        ->end()
                    ->end()
                ->end()

                // Advanced Options
                ->booleanNode('async')
                    ->defaultTrue()
                    ->info('Use async HTTP client (fire-and-forget for maximum resilience)')
                ->end()
                ->booleanNode('debug')
                    ->defaultFalse()
                    ->info('Enable PHP debug logging')
                ->end()
            ->end()
            ->validate()
                ->ifTrue(function (array $v): bool {
                    return $v['heatmap']['enabled'] && !$v['session_tracking']['enabled'];
                })
                ->thenInvalid('Heatmap tracking requires session tracking to be enabled. Set session_tracking.enabled to true.')
            ->end()
        ;

        return $treeBuilder;
    }
}
