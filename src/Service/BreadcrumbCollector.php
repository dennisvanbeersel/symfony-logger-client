<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Service;

/**
 * Breadcrumb Collector.
 *
 * Tracks user actions and events leading up to an error.
 * Provides context for debugging by showing the user's journey.
 *
 * RESILIENCE: All methods wrapped in try-catch, never throws exceptions.
 */
class BreadcrumbCollector
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $breadcrumbs = [];

    public function __construct(
        private readonly int $maxBreadcrumbs = 50,
    ) {
    }

    /**
     * Add a breadcrumb.
     *
     * @param array<string, mixed> $breadcrumb
     */
    public function add(array $breadcrumb): void
    {
        try {
            $this->breadcrumbs[] = [
                'timestamp' => $breadcrumb['timestamp'] ?? (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
                'level' => $breadcrumb['level'] ?? 'info',
                'type' => $breadcrumb['type'] ?? 'default',
                'category' => $breadcrumb['category'] ?? 'manual',
                'message' => $breadcrumb['message'] ?? '',
                'data' => $breadcrumb['data'] ?? [],
            ];

            // Limit breadcrumbs to prevent memory issues
            if (\count($this->breadcrumbs) > $this->maxBreadcrumbs) {
                array_shift($this->breadcrumbs);
            }
        } catch (\Throwable) {
            // Never crash on breadcrumb collection
        }
    }

    /**
     * Add an HTTP request breadcrumb.
     */
    public function addHttpRequest(string $method, string $url, int $statusCode, float $duration): void
    {
        $this->add([
            'type' => 'http',
            'category' => 'http',
            'message' => \sprintf('%s %s', $method, $url),
            'level' => $statusCode >= 400 ? 'warning' : 'info',
            'data' => [
                'method' => $method,
                'url' => $url,
                'status_code' => $statusCode,
                'duration' => $duration,
            ],
        ]);
    }

    /**
     * Add a database query breadcrumb.
     */
    public function addDatabaseQuery(string $query, float $duration): void
    {
        $this->add([
            'type' => 'query',
            'category' => 'database',
            'message' => $query,
            'level' => $duration > 1.0 ? 'warning' : 'info',
            'data' => [
                'query' => $query,
                'duration' => $duration,
            ],
        ]);
    }

    /**
     * Add a navigation breadcrumb.
     */
    public function addNavigation(string $from, string $to): void
    {
        $this->add([
            'type' => 'navigation',
            'category' => 'navigation',
            'message' => \sprintf('Navigated from %s to %s', $from, $to),
            'data' => [
                'from' => $from,
                'to' => $to,
            ],
        ]);
    }

    /**
     * Add a user action breadcrumb.
     *
     * @param array<string, mixed> $data
     */
    public function addUserAction(string $action, array $data = []): void
    {
        $this->add([
            'type' => 'user',
            'category' => 'action',
            'message' => $action,
            'data' => $data,
        ]);
    }

    /**
     * Get all breadcrumbs.
     *
     * @return list<array<string, mixed>>
     */
    public function get(): array
    {
        return $this->breadcrumbs;
    }

    /**
     * Clear all breadcrumbs.
     */
    public function clear(): void
    {
        $this->breadcrumbs = [];
    }

    /**
     * Get breadcrumb count.
     */
    public function count(): int
    {
        return \count($this->breadcrumbs);
    }
}
