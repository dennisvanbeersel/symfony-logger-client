<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Service;

use ApplicationLogger\Bundle\DependencyInjection\Configuration;
use ApplicationLogger\Bundle\Service\DataScrubber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

/**
 * Verifies the DEFAULT scrub_fields list (as produced by the bundle
 * Configuration) redacts privacy-sensitive financial / identity fields,
 * not just credentials.
 */
final class DataScrubberDefaultFieldsTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function defaultScrubFields(): array
    {
        $processed = (new Processor())->processConfiguration(
            new Configuration(),
            [['dsn' => 'https://example.com/1', 'api_key' => 'test-key']]
        );

        /** @var list<string> $fields */
        $fields = $processed['scrub_fields'];

        return $fields;
    }

    public function testDefaultConfigRedactsCreditCardAndSsn(): void
    {
        $scrubber = new DataScrubber($this->defaultScrubFields());

        $scrubbed = $scrubber->scrub([
            'name' => 'John Doe',
            'credit_card' => '4111111111111111',
            'ssn' => '123-45-6789',
        ]);

        $this->assertSame('John Doe', $scrubbed['name']);
        $this->assertSame('[REDACTED]', $scrubbed['credit_card']);
        $this->assertSame('[REDACTED]', $scrubbed['ssn']);
    }

    public function testDefaultConfigRedactsCommonFinancialAliases(): void
    {
        $scrubber = new DataScrubber($this->defaultScrubFields());

        $scrubbed = $scrubber->scrub([
            'creditcard' => '4111111111111111',
            'card_number' => '4111111111111111',
            'cvv' => '123',
            'iban' => 'DE89370400440532013000',
            'password' => 'hunter2',
        ]);

        $this->assertSame('[REDACTED]', $scrubbed['creditcard']);
        $this->assertSame('[REDACTED]', $scrubbed['card_number']);
        $this->assertSame('[REDACTED]', $scrubbed['cvv']);
        $this->assertSame('[REDACTED]', $scrubbed['iban']);
        $this->assertSame('[REDACTED]', $scrubbed['password']);
    }
}
