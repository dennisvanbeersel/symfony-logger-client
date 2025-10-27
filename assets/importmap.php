<?php

declare(strict_types=1);

/**
 * AssetMapper Importmap Configuration.
 *
 * This file defines the JavaScript modules exposed by the ApplicationLogger bundle.
 * The host application can import these modules via AssetMapper.
 *
 * Usage in host application:
 *   import ApplicationLogger from '@application-logger/bundle';
 */

return [
    // Main entry point
    '@application-logger/bundle' => [
        'path' => 'src/index.js',
        'entrypoint' => true,
    ],

    // Individual modules (for advanced usage)
    '@application-logger/client' => [
        'path' => 'src/client.js',
    ],

    '@application-logger/transport' => [
        'path' => 'src/transport.js',
    ],

    '@application-logger/breadcrumbs' => [
        'path' => 'src/breadcrumbs.js',
    ],

    '@application-logger/circuit-breaker' => [
        'path' => 'src/circuit-breaker.js',
    ],

    '@application-logger/storage-queue' => [
        'path' => 'src/storage-queue.js',
    ],

    '@application-logger/rate-limiter' => [
        'path' => 'src/rate-limiter.js',
    ],
];
