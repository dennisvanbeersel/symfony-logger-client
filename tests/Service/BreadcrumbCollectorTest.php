<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Service;

use ApplicationLogger\Bundle\Service\BreadcrumbCollector;
use PHPUnit\Framework\TestCase;

final class BreadcrumbCollectorTest extends TestCase
{
    public function testAddBreadcrumbWithAllFields(): void
    {
        $collector = new BreadcrumbCollector();

        $collector->add([
            'timestamp' => '2024-01-15T10:30:00+00:00',
            'level' => 'warning',
            'type' => 'http',
            'category' => 'request',
            'message' => 'API call failed',
            'data' => ['status' => 500],
        ]);

        $breadcrumbs = $collector->get();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('2024-01-15T10:30:00+00:00', $breadcrumbs[0]['timestamp']);
        $this->assertEquals('warning', $breadcrumbs[0]['level']);
        $this->assertEquals('http', $breadcrumbs[0]['type']);
        $this->assertEquals('request', $breadcrumbs[0]['category']);
        $this->assertEquals('API call failed', $breadcrumbs[0]['message']);
        $this->assertEquals(['status' => 500], $breadcrumbs[0]['data']);
    }

    public function testAddBreadcrumbWithDefaultValues(): void
    {
        $collector = new BreadcrumbCollector();

        $collector->add([
            'message' => 'Simple action',
        ]);

        $breadcrumbs = $collector->get();

        $this->assertCount(1, $breadcrumbs);
        $this->assertArrayHasKey('timestamp', $breadcrumbs[0]);
        $this->assertEquals('info', $breadcrumbs[0]['level']);
        $this->assertEquals('default', $breadcrumbs[0]['type']);
        $this->assertEquals('manual', $breadcrumbs[0]['category']);
        $this->assertEquals('Simple action', $breadcrumbs[0]['message']);
        $this->assertEquals([], $breadcrumbs[0]['data']);
    }

    public function testMaxBreadcrumbsLimitEnforced(): void
    {
        $maxBreadcrumbs = 5;
        $collector = new BreadcrumbCollector($maxBreadcrumbs);

        // Add more breadcrumbs than the limit
        for ($i = 1; $i <= 8; ++$i) {
            $collector->add(['message' => "Breadcrumb {$i}"]);
        }

        $breadcrumbs = $collector->get();

        // Should only keep the last 5 (FIFO)
        $this->assertCount(5, $breadcrumbs);
        $this->assertEquals('Breadcrumb 4', $breadcrumbs[0]['message']);
        $this->assertEquals('Breadcrumb 8', $breadcrumbs[4]['message']);
    }

    public function testFifoOrderMaintained(): void
    {
        $collector = new BreadcrumbCollector(3);

        $collector->add(['message' => 'First']);
        $collector->add(['message' => 'Second']);
        $collector->add(['message' => 'Third']);
        $collector->add(['message' => 'Fourth']); // This should push out 'First'

        $breadcrumbs = $collector->get();

        $this->assertCount(3, $breadcrumbs);
        $this->assertEquals('Second', $breadcrumbs[0]['message']);
        $this->assertEquals('Third', $breadcrumbs[1]['message']);
        $this->assertEquals('Fourth', $breadcrumbs[2]['message']);
    }

    public function testAddHttpRequestBreadcrumb(): void
    {
        $collector = new BreadcrumbCollector();

        $collector->addHttpRequest('POST', 'https://api.example.com/users', 201, 0.5);

        $breadcrumbs = $collector->get();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('http', $breadcrumbs[0]['type']);
        $this->assertEquals('http', $breadcrumbs[0]['category']);
        $this->assertEquals('POST https://api.example.com/users', $breadcrumbs[0]['message']);
        $this->assertEquals('info', $breadcrumbs[0]['level']); // Status < 400
        $this->assertEquals([
            'method' => 'POST',
            'url' => 'https://api.example.com/users',
            'status_code' => 201,
            'duration' => 0.5,
        ], $breadcrumbs[0]['data']);
    }

    public function testAddHttpRequestBreadcrumbWithErrorStatusCode(): void
    {
        $collector = new BreadcrumbCollector();

        $collector->addHttpRequest('GET', 'https://api.example.com/missing', 404, 0.1);

        $breadcrumbs = $collector->get();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('warning', $breadcrumbs[0]['level']); // Status >= 400
        $this->assertEquals(404, $breadcrumbs[0]['data']['status_code']);
    }

    public function testAddDatabaseQueryBreadcrumb(): void
    {
        $collector = new BreadcrumbCollector();

        $collector->addDatabaseQuery('SELECT * FROM users WHERE id = ?', 0.05);

        $breadcrumbs = $collector->get();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('query', $breadcrumbs[0]['type']);
        $this->assertEquals('database', $breadcrumbs[0]['category']);
        $this->assertEquals('SELECT * FROM users WHERE id = ?', $breadcrumbs[0]['message']);
        $this->assertEquals('info', $breadcrumbs[0]['level']); // Duration < 1.0
        $this->assertEquals([
            'query' => 'SELECT * FROM users WHERE id = ?',
            'duration' => 0.05,
        ], $breadcrumbs[0]['data']);
    }

    public function testAddDatabaseQueryBreadcrumbSlowQuery(): void
    {
        $collector = new BreadcrumbCollector();

        $collector->addDatabaseQuery('SELECT * FROM large_table', 1.5);

        $breadcrumbs = $collector->get();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('warning', $breadcrumbs[0]['level']); // Duration > 1.0 = slow query
    }

    public function testAddNavigationBreadcrumb(): void
    {
        $collector = new BreadcrumbCollector();

        $collector->addNavigation('/home', '/dashboard');

        $breadcrumbs = $collector->get();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('navigation', $breadcrumbs[0]['type']);
        $this->assertEquals('navigation', $breadcrumbs[0]['category']);
        $this->assertEquals('Navigated from /home to /dashboard', $breadcrumbs[0]['message']);
        $this->assertEquals([
            'from' => '/home',
            'to' => '/dashboard',
        ], $breadcrumbs[0]['data']);
    }

    public function testAddUserActionBreadcrumb(): void
    {
        $collector = new BreadcrumbCollector();

        $collector->addUserAction('Clicked submit button', ['form_id' => 'login-form']);

        $breadcrumbs = $collector->get();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('user', $breadcrumbs[0]['type']);
        $this->assertEquals('action', $breadcrumbs[0]['category']);
        $this->assertEquals('Clicked submit button', $breadcrumbs[0]['message']);
        $this->assertEquals(['form_id' => 'login-form'], $breadcrumbs[0]['data']);
    }

    public function testClearRemovesAllBreadcrumbs(): void
    {
        $collector = new BreadcrumbCollector();

        $collector->add(['message' => 'First']);
        $collector->add(['message' => 'Second']);
        $this->assertCount(2, $collector->get());

        $collector->clear();

        $this->assertCount(0, $collector->get());
        $this->assertEquals(0, $collector->count());
    }

    public function testCountReturnsCorrectNumber(): void
    {
        $collector = new BreadcrumbCollector();

        $this->assertEquals(0, $collector->count());

        $collector->add(['message' => 'First']);
        $this->assertEquals(1, $collector->count());

        $collector->add(['message' => 'Second']);
        $this->assertEquals(2, $collector->count());

        $collector->add(['message' => 'Third']);
        $this->assertEquals(3, $collector->count());
    }

    public function testGetReturnsEmptyArrayWhenNoBreadcrumbs(): void
    {
        $collector = new BreadcrumbCollector();

        $breadcrumbs = $collector->get();

        $this->assertCount(0, $breadcrumbs);
    }

    public function testTimestampIsAutoGeneratedWhenMissing(): void
    {
        $collector = new BreadcrumbCollector();

        $before = (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM);
        $collector->add(['message' => 'Test']);
        $after = (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM);

        $breadcrumbs = $collector->get();
        $timestamp = $breadcrumbs[0]['timestamp'];

        // The timestamp should be between before and after
        $this->assertGreaterThanOrEqual($before, $timestamp);
        $this->assertLessThanOrEqual($after, $timestamp);
    }

    public function testResilienceOnInvalidData(): void
    {
        $collector = new BreadcrumbCollector();

        // Even with invalid data, the collector should not throw
        $collector->add(['message' => null]);
        $collector->add(['data' => 'not an array']); // data should be array but we're passing string

        // Should have 2 breadcrumbs (the collector normalizes the data)
        $this->assertEquals(2, $collector->count());
    }

    public function testDefaultMaxBreadcrumbsIs50(): void
    {
        $collector = new BreadcrumbCollector();

        // Add 55 breadcrumbs
        for ($i = 1; $i <= 55; ++$i) {
            $collector->add(['message' => "Breadcrumb {$i}"]);
        }

        // Should only keep the last 50
        $this->assertCount(50, $collector->get());
        $this->assertEquals('Breadcrumb 6', $collector->get()[0]['message']);
        $this->assertEquals('Breadcrumb 55', $collector->get()[49]['message']);
    }

    public function testMultipleBreadcrumbTypesMixed(): void
    {
        $collector = new BreadcrumbCollector();

        $collector->addNavigation('/', '/login');
        $collector->addUserAction('Entered username');
        $collector->addUserAction('Entered password');
        $collector->addUserAction('Clicked login');
        $collector->addHttpRequest('POST', '/api/login', 200, 0.3);
        $collector->addDatabaseQuery('SELECT * FROM users WHERE email = ?', 0.02);
        $collector->addNavigation('/login', '/dashboard');

        $breadcrumbs = $collector->get();

        $this->assertCount(7, $breadcrumbs);
        $this->assertEquals('navigation', $breadcrumbs[0]['type']);
        $this->assertEquals('user', $breadcrumbs[1]['type']);
        $this->assertEquals('user', $breadcrumbs[2]['type']);
        $this->assertEquals('user', $breadcrumbs[3]['type']);
        $this->assertEquals('http', $breadcrumbs[4]['type']);
        $this->assertEquals('query', $breadcrumbs[5]['type']);
        $this->assertEquals('navigation', $breadcrumbs[6]['type']);
    }
}
