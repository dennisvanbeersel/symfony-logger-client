<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\DependencyInjection;

use ApplicationLogger\Bundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
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
}
