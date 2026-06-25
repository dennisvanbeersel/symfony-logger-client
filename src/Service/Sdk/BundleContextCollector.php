<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Service\Sdk;

use ApplicationLogger\Bundle\Service\ContextCollectorInterface as BundleContextCollectorInterface;
use ApplicationLogger\Sdk\Context\ContextCollectorInterface as SdkContextCollectorInterface;

/**
 * Adapts the bundle's RequestStack-based ContextCollector to sdk-core's single
 * collect() seam, so sdk-core's Client uses the bundle's proxy-aware, scrubbed
 * context (NOT sdk-core's GlobalsContextCollector, which reads $_SERVER and would
 * report the proxy IP). Total — never throws.
 */
final readonly class BundleContextCollector implements SdkContextCollectorInterface
{
    public function __construct(private BundleContextCollectorInterface $inner)
    {
    }

    /** @return array<string, mixed> */
    public function collect(): array
    {
        try {
            $ctx = $this->inner->collectContext();
            $request = \is_array($ctx['request'] ?? null) ? $ctx['request'] : [];
            $env = \is_array($request['env'] ?? null) ? $request['env'] : [];
            $server = \is_array($ctx['server'] ?? null) ? $ctx['server'] : [];

            $out = [
                'runtime' => 'PHP '.\PHP_VERSION,
                'request' => $request,
                'user' => $ctx['user'] ?? null,
                'server' => $server,
            ];
            if (isset($request['url']) && \is_string($request['url'])) {
                $out['url'] = $request['url'];
            }
            if (isset($request['method']) && \is_string($request['method'])) {
                $out['http_method'] = $request['method'];
            }
            if (isset($env['REMOTE_ADDR']) && \is_string($env['REMOTE_ADDR'])) {
                $out['ip_address'] = $env['REMOTE_ADDR'];
            }
            if (isset($ctx['session_hash']) && \is_string($ctx['session_hash'])) {
                $out['session_hash'] = $ctx['session_hash'];
            }
            if (isset($server['server_name']) && \is_string($server['server_name'])) {
                $out['server_name'] = $server['server_name'];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }
}
