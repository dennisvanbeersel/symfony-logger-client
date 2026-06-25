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

    public function testLoopbackPathsDefaultsToFivePrefixes(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), []);

        self::assertSame(
            ['/api/v1/errors', '/api/v1/js-errors', '/api/v1/sessions', '/api/v1/logs', '/api/errors'],
            $config['loopback_paths'],
        );
    }

    public function testSessionHashSaltDefaultsToNull(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), []);

        self::assertNull($config['session_hash_salt']);
    }

    public function testLoopbackPathsCanBeOverridden(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), [[
            'loopback_paths' => ['/custom/ingest'],
        ]]);

        self::assertSame(['/custom/ingest'], $config['loopback_paths']);
    }

    public function testSessionHashSaltCanBeSet(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), [[
            'session_hash_salt' => 'my-custom-salt',
        ]]);

        self::assertSame('my-custom-salt', $config['session_hash_salt']);
    }

    /**
     * A deprecated key like retry_attempts: 2 must still COMPILE (no "unrecognized option"
     * exception) — it just emits a deprecation notice.
     */
    public function testDeprecatedRetryAttemptsStillCompiles(): void
    {
        $processor = new Processor();

        // Must not throw an InvalidConfigurationException
        $config = @$processor->processConfiguration(new Configuration(), [[
            'retry_attempts' => 2,
        ]]);

        self::assertSame(2, $config['retry_attempts']);
    }

    /**
     * GDPR coverage: the bundle's shipped default scrub_fields MUST produce redaction
     * when passed through sdk-core's DataScrubber. This test locks in that the bundle's
     * default configuration actually scrubs sensitive values — a regression here would
     * be a GDPR violation in every clean install.
     *
     * The redaction marker '[REDACTED]' is the canonical value used by
     * ApplicationLogger\Sdk\DataScrubber::scrubInternal().
     *
     * @dataProvider defaultScrubFieldsProvider
     */
    public function testDefaultScrubFieldsRedactThroughSdkCoreScrubber(string $field): void
    {
        $defaultScrubFields = (new Processor())->processConfiguration(new Configuration(), [[]])['scrub_fields'];

        $scrubber = new \ApplicationLogger\Sdk\DataScrubber($defaultScrubFields);

        $result = $scrubber->scrub([$field => 'sensitive-value-that-must-not-leak']);

        self::assertSame('[REDACTED]', $result[$field], \sprintf(
            'Default scrub field "%s" must be redacted by sdk-core DataScrubber, but value was passed through.',
            $field,
        ));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function defaultScrubFieldsProvider(): array
    {
        return [
            'password' => ['password'],
            'token' => ['token'],
            'api_key' => ['api_key'],
            'secret' => ['secret'],
            'authorization' => ['authorization'],
            'credit_card' => ['credit_card'],
            'creditcard' => ['creditcard'],
            'card_number' => ['card_number'],
            'cvv' => ['cvv'],
            'ssn' => ['ssn'],
            'iban' => ['iban'],
        ];
    }
}
