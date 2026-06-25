<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Service\Sdk;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Same-host loopback guard for self-monitoring. The platform serves both the
 * dashboard AND the ingestion API on one host, so the guard is PATH-based (is the
 * CURRENT request an ingestion route?), not host-based. Lazily evaluated via
 * RequestStack so it is worker-safe. Total.
 */
final readonly class LoopbackGuard
{
    /** @param list<string> $ingestPaths */
    public function __construct(
        private RequestStack $requestStack,
        private array $ingestPaths,
    ) {
    }

    public function isIngestRequest(): bool
    {
        try {
            $request = $this->requestStack->getCurrentRequest();
            if (null === $request) {
                return false; // no web request (CLI/worker boot) -> not a loopback
            }
            $path = $request->getPathInfo();
            foreach ($this->ingestPaths as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable) {
            return false;
        }
    }
}
