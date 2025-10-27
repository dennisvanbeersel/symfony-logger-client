<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Service;

/**
 * DSN Generator Service.
 *
 * Generates proper DSN (Data Source Name) strings for projects.
 *
 * DSN Format: {protocol}://{host}/{projectId}
 * Example: https://localhost:8111/b6d8ed85-c0af-4c02-b6bb-bfb0f3609b37
 *
 * Note: The API key is NOT included in the DSN. It's sent separately as the X-Api-Key header.
 * This keeps authentication credentials secure and separate from the endpoint URL.
 */
final class DsnGenerator
{
    public function __construct(
        private readonly string $baseUrl,
    ) {
    }

    /**
     * Generate a complete DSN string.
     *
     * DSN identifies the project endpoint but does NOT include the API key.
     * The API key must be configured separately and sent as X-Api-Key header.
     *
     * @param string $projectId The project UUID
     *
     * @return string Complete DSN string (without API key)
     */
    public function generateDsn(string $projectId): string
    {
        // Simply append the project ID to the base URL
        return rtrim($this->baseUrl, '/').'/'.$projectId;
    }

    /**
     * Extract components from a DSN string.
     *
     * @return array{scheme: string, host: string, port: int|null, projectId: string}|null
     */
    public function parseDsn(string $dsn): ?array
    {
        $parsed = parse_url($dsn);

        if (false === $parsed || !isset($parsed['scheme'], $parsed['host'], $parsed['path'])) {
            return null;
        }

        $projectId = ltrim($parsed['path'], '/');

        if ('' === $projectId) {
            return null;
        }

        return [
            'scheme' => $parsed['scheme'],
            'host' => $parsed['host'],
            'port' => $parsed['port'] ?? null,
            'projectId' => $projectId,
        ];
    }

    /**
     * Get the base URL without protocol.
     *
     * @return string Example: localhost:8111
     */
    public function getHostWithPort(): string
    {
        $parsed = parse_url($this->baseUrl);

        if (false === $parsed || !isset($parsed['host'])) {
            throw new \InvalidArgumentException(\sprintf('Invalid base URL: %s', $this->baseUrl));
        }

        $host = $parsed['host'];
        $port = $parsed['port'] ?? null;

        return null !== $port ? $host.':'.$port : $host;
    }

    /**
     * Get the API endpoint URL for error ingestion.
     *
     * @return string Example: https://localhost:8111/api/errors/ingest
     */
    public function getIngestEndpoint(): string
    {
        return rtrim($this->baseUrl, '/').'/api/errors/ingest';
    }
}
