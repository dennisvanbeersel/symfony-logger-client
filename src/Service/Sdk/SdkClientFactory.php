<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Service\Sdk;

use ApplicationLogger\Sdk\Client;
use ApplicationLogger\Sdk\Clock\SystemClock;
use ApplicationLogger\Sdk\DataScrubber;
use ApplicationLogger\Sdk\Event;
use ApplicationLogger\Sdk\Hub;
use ApplicationLogger\Sdk\Log\LogClientFactory;
use ApplicationLogger\Sdk\Log\LogConfig;
use ApplicationLogger\Sdk\Options;
use ApplicationLogger\Sdk\Scope;
use ApplicationLogger\Sdk\StackTraceParser;
use ApplicationLogger\Sdk\Transport\TransportFactory;
use Psr\Log\LoggerInterface;

/**
 * Builds and holds the singleton sdk-core Client + LogClient (+ Hub) from the
 * bundle config. Injects the bundle's context collector (not sdk-core's globals
 * collector). Registers the path-based loopback before_send. Total — never throws.
 */
final class SdkClientFactory
{
    private ?Hub $hub = null;

    /** @var list<string> */
    private array $resolvedScrubFields = [];

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly BundleContextCollector $context,
        private readonly LoopbackGuard $loopback,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function getHub(): Hub
    {
        if (null === $this->hub) {
            $this->hub = $this->build();
            Hub::setCurrent($this->hub);
        }

        return $this->hub;
    }

    /** @return list<string> */
    public function getResolvedScrubFields(): array
    {
        $this->getHub();

        return $this->resolvedScrubFields;
    }

    private function build(): Hub
    {
        $dsn = \is_string($this->config['dsn'] ?? null) ? $this->config['dsn'] : '';
        $apiKey = \is_string($this->config['api_key'] ?? null) ? $this->config['api_key'] : '';
        $enabled = (bool) ($this->config['enabled'] ?? true);

        // api_key MUST be non-empty when a DSN is set (sdk-core only sends the auth header
        // when api_key is non-null); otherwise disable the error transport so we never
        // ship unauthenticated telemetry.
        if ($enabled && '' !== $dsn && '' === $apiKey) {
            $this->logger?->warning(
                'ApplicationLogger: api_key is empty; error tracking disabled (DSN-only auth is not reliable).',
            );
            $enabled = false;
        }

        /** @var list<string> $scrub */
        $scrub = array_values(array_filter(
            (array) ($this->config['scrub_fields'] ?? []),
            static fn (mixed $v): bool => \is_string($v) && '' !== $v,
        ));
        $this->resolvedScrubFields = $scrub;

        $cb = \is_array($this->config['circuit_breaker'] ?? null) ? $this->config['circuit_breaker'] : [];

        $optionsInput = [
            'dsn' => '' !== $dsn ? $dsn : null,
            'api_key' => '' !== $apiKey ? $apiKey : null,
            'environment' => $this->config['environment'] ?? 'production',
            'release' => $this->config['release'] ?? null,
            'enabled' => $enabled,
            'scrub_fields' => $scrub,
            'max_breadcrumbs' => (int) ($this->config['max_breadcrumbs'] ?? 50),
            'timeout' => (float) ($this->config['timeout'] ?? 2.0),
            // Clamp to the bundle's tighter 0.05–2.0 BEFORE sdk-core re-clamps to 0.05–5.0.
            'flush_budget' => max(0.05, min(2.0, (float) ($this->config['flush_budget'] ?? 2.0))),
            'circuit_breaker' => [
                'failure_threshold' => (int) ($cb['failure_threshold'] ?? 5),
                'timeout' => (int) ($cb['timeout'] ?? 60),
                'half_open_attempts' => (int) ($cb['half_open_attempts'] ?? 1),
            ],
            'session_hash_salt' => $this->config['session_hash_salt'] ?? null,
            'cache_dir' => $this->config['cache_dir'] ?? null,
            'default_integrations' => false, // Symfony owns exception handling
            'before_send' => $this->loopbackBeforeSend(),
            'log_endpoint' => $this->config['log_endpoint'] ?? null,
            'log_token' => $this->config['log_token'] ?? null,
            'app_name' => $this->config['app_name'] ?? null,
        ];

        $opts = Options::fromArray($optionsInput);

        $literals = array_values(array_filter(
            [null !== $opts->dsn ? $opts->dsn->raw : null, $opts->apiKey],
            static fn (?string $v): bool => \is_string($v) && '' !== $v,
        ));
        $scrubber = new DataScrubber($opts->scrubFields, $literals);

        $client = new Client(
            $opts,
            TransportFactory::create($opts),
            new SystemClock(),
            $scrubber,
            new StackTraceParser(),
            $this->context, // the bundle's proxy-aware collector, not sdk-core's GlobalsContextCollector
        );

        $logClient = LogClientFactory::create(LogConfig::fromArray($optionsInput), $scrubber);

        return new Hub($client, new Scope($opts->maxBreadcrumbs), $logClient);
    }

    private function loopbackBeforeSend(): \Closure
    {
        $loopback = $this->loopback;

        return static function (Event $event) use ($loopback): ?Event {
            return $loopback->isIngestRequest() ? null : $event;
        };
    }
}
