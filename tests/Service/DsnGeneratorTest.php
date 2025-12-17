<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Service;

use ApplicationLogger\Bundle\Service\DsnGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DsnGenerator::class)]
final class DsnGeneratorTest extends TestCase
{
    public function testGenerateDsnWithSimpleBaseUrl(): void
    {
        $generator = new DsnGenerator('https://applogger.eu');
        $dsn = $generator->generateDsn('b6d8ed85-c0af-4c02-b6bb-bfb0f3609b37');

        $this->assertSame('https://applogger.eu/b6d8ed85-c0af-4c02-b6bb-bfb0f3609b37', $dsn);
    }

    public function testGenerateDsnWithTrailingSlash(): void
    {
        $generator = new DsnGenerator('https://applogger.eu/');
        $dsn = $generator->generateDsn('project-123');

        $this->assertSame('https://applogger.eu/project-123', $dsn);
    }

    public function testGenerateDsnWithPort(): void
    {
        $generator = new DsnGenerator('https://localhost:8111');
        $dsn = $generator->generateDsn('test-project');

        $this->assertSame('https://localhost:8111/test-project', $dsn);
    }

    public function testParseDsnWithValidUrl(): void
    {
        $generator = new DsnGenerator('https://applogger.eu');
        $result = $generator->parseDsn('https://applogger.eu/b6d8ed85-c0af-4c02-b6bb-bfb0f3609b37');

        $this->assertNotNull($result);
        $this->assertSame('https', $result['scheme']);
        $this->assertSame('applogger.eu', $result['host']);
        $this->assertNull($result['port']);
        $this->assertSame('b6d8ed85-c0af-4c02-b6bb-bfb0f3609b37', $result['projectId']);
    }

    public function testParseDsnWithPort(): void
    {
        $generator = new DsnGenerator('https://localhost:8111');
        $result = $generator->parseDsn('https://localhost:8111/project-123');

        $this->assertNotNull($result);
        $this->assertSame('https', $result['scheme']);
        $this->assertSame('localhost', $result['host']);
        $this->assertSame(8111, $result['port']);
        $this->assertSame('project-123', $result['projectId']);
    }

    public function testParseDsnWithHttpScheme(): void
    {
        $generator = new DsnGenerator('http://localhost');
        $result = $generator->parseDsn('http://localhost/my-project');

        $this->assertNotNull($result);
        $this->assertSame('http', $result['scheme']);
        $this->assertSame('localhost', $result['host']);
        $this->assertSame('my-project', $result['projectId']);
    }

    #[DataProvider('invalidDsnProvider')]
    public function testParseDsnWithInvalidUrlReturnsNull(string $invalidDsn): void
    {
        $generator = new DsnGenerator('https://applogger.eu');
        $result = $generator->parseDsn($invalidDsn);

        $this->assertNull($result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidDsnProvider(): array
    {
        return [
            'empty string' => [''],
            'no scheme' => ['applogger.eu/project'],
            'no host' => ['https:///project'],
            'no path' => ['https://applogger.eu'],
            'empty path' => ['https://applogger.eu/'],
            'malformed url' => ['not-a-url'],
        ];
    }

    public function testGetHostWithPortWithoutPort(): void
    {
        $generator = new DsnGenerator('https://applogger.eu');
        $hostWithPort = $generator->getHostWithPort();

        $this->assertSame('applogger.eu', $hostWithPort);
    }

    public function testGetHostWithPortWithPort(): void
    {
        $generator = new DsnGenerator('https://localhost:8111');
        $hostWithPort = $generator->getHostWithPort();

        $this->assertSame('localhost:8111', $hostWithPort);
    }

    public function testGetHostWithPortWithNonStandardPort(): void
    {
        $generator = new DsnGenerator('https://api.example.com:9000');
        $hostWithPort = $generator->getHostWithPort();

        $this->assertSame('api.example.com:9000', $hostWithPort);
    }

    public function testGetHostWithPortThrowsExceptionForInvalidUrl(): void
    {
        $generator = new DsnGenerator('not-a-valid-url');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid base URL');

        $generator->getHostWithPort();
    }

    public function testGetIngestEndpoint(): void
    {
        $generator = new DsnGenerator('https://applogger.eu');
        $endpoint = $generator->getIngestEndpoint();

        $this->assertSame('https://applogger.eu/api/errors/ingest', $endpoint);
    }

    public function testGetIngestEndpointWithTrailingSlash(): void
    {
        $generator = new DsnGenerator('https://applogger.eu/');
        $endpoint = $generator->getIngestEndpoint();

        $this->assertSame('https://applogger.eu/api/errors/ingest', $endpoint);
    }

    public function testGetIngestEndpointWithPort(): void
    {
        $generator = new DsnGenerator('https://localhost:8111');
        $endpoint = $generator->getIngestEndpoint();

        $this->assertSame('https://localhost:8111/api/errors/ingest', $endpoint);
    }

    public function testRoundTripGenerateAndParse(): void
    {
        $generator = new DsnGenerator('https://applogger.eu');
        $projectId = 'b6d8ed85-c0af-4c02-b6bb-bfb0f3609b37';

        $dsn = $generator->generateDsn($projectId);
        $parsed = $generator->parseDsn($dsn);

        $this->assertNotNull($parsed);
        $this->assertSame($projectId, $parsed['projectId']);
    }
}
