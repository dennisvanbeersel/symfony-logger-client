<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\DependencyInjection;

use ApplicationLogger\Bundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    /**
     * @param array<int, array<string, mixed>> $configs
     *
     * @return array<string, mixed>
     */
    private function process(array $configs): array
    {
        return (new Processor())->processConfiguration(new Configuration(), $configs);
    }

    /**
     * A clean install where the Flex recipe was not applied leaves the bundle
     * enabled with NO configuration. Processing an empty config MUST succeed so
     * `cache:clear` does not break the host application (resilience rule #1).
     */
    public function testEmptyConfigDoesNotThrow(): void
    {
        $config = $this->process([[]]);

        $this->assertSame('', $config['dsn']);
        $this->assertSame('', $config['api_key']);
    }

    public function testDsnAndApiKeyAreConfigurableWhenProvided(): void
    {
        $config = $this->process([[
            'dsn' => 'https://example.com/project-id',
            'api_key' => 'secret-key',
        ]]);

        $this->assertSame('https://example.com/project-id', $config['dsn']);
        $this->assertSame('secret-key', $config['api_key']);
    }

    public function testFlushBudgetDefaultsToTwoSeconds(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), []);

        self::assertSame(2.0, $config['flush_budget']);
    }

    public function testFlushBudgetRejectsOutOfRange(): void
    {
        $processor = new Processor();

        $this->expectException(InvalidConfigurationException::class);
        $processor->processConfiguration(new Configuration(), [['flush_budget' => 0.04]]);
    }

    public function testFlushBudgetRejectsAboveMax(): void
    {
        $processor = new Processor();

        $this->expectException(InvalidConfigurationException::class);
        $processor->processConfiguration(new Configuration(), [['flush_budget' => 9.0]]);
    }

    public function testExcludedChannelsDefault(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), []);

        self::assertSame(['http_client', 'console', 'deprecation', 'doctrine'], $config['excluded_channels']);
    }

    public function testExcludedChannelsCanBeOverriddenToEmpty(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), [['excluded_channels' => []]]);

        self::assertSame([], $config['excluded_channels']);
    }

    public function testTogglesDefaultTrue(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), []);

        self::assertTrue($config['error_tracking_enabled']);
        self::assertTrue($config['log_aggregation_enabled']);
    }
}
