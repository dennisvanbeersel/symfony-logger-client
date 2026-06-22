<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Command;

use ApplicationLogger\Bundle\Service\ApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'application-logger:test',
    description: 'Send a test LogEntry to the configured collector and report the outcome',
)]
final class TestConnectivityCommand extends Command
{
    public function __construct(
        private readonly ApiClient $apiClient,
        private readonly bool $enabled,
        private readonly ?string $logEndpoint,
        private readonly ?string $logToken,
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->enabled) {
            $io->warning('ApplicationLogger is disabled (application_logger.enabled=false) — nothing was sent.');

            return Command::FAILURE;
        }

        if (\in_array($this->logEndpoint, [null, ''], true) || \in_array($this->logToken, [null, ''], true)) {
            $io->warning('Log aggregation is not configured (log_endpoint / log_token missing).');

            return Command::FAILURE;
        }

        $io->writeln(\sprintf('Sending a test LogEntry to <info>%s</info> …', $this->logEndpoint));

        $status = $this->apiClient->sendLogSync([
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
            'severity' => 'info',
            'message' => 'ApplicationLogger connectivity test',
            'app_name' => 'application-logger-test',
            'environment' => $this->environment,
            'context' => ['source' => 'application-logger:test'],
        ]);

        if (202 === $status) {
            $io->success('Test log delivered (HTTP 202 Accepted).');

            return Command::SUCCESS;
        }

        if (null === $status) {
            $io->error('Collector unreachable or transport failure (check endpoint/network; the circuit breaker may be open).');

            return Command::FAILURE;
        }

        $io->error(\sprintf('Collector rejected the test log (HTTP %d) — check the log token / endpoint.', $status));

        return Command::FAILURE;
    }
}
