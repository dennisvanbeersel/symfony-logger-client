<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Command;

use ApplicationLogger\Bundle\Command\TestConnectivityCommand;
use ApplicationLogger\Bundle\Service\ApiClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class TestConnectivityCommandTest extends TestCase
{
    private function tester(ApiClient $client, bool $enabled, ?string $endpoint, ?string $token): CommandTester
    {
        return new CommandTester(new TestConnectivityCommand($client, $enabled, $endpoint, $token, 'test'));
    }

    public function testReportsDisabled(): void
    {
        $client = $this->createMock(ApiClient::class);
        $client->expects(self::never())->method('sendLogSync');
        $tester = $this->tester($client, enabled: false, endpoint: 'https://l', token: 'sk');
        self::assertNotSame(0, $tester->execute([]));
        self::assertStringContainsString('disabled', $tester->getDisplay());
    }

    public function testReportsMissingCredentials(): void
    {
        $client = $this->createMock(ApiClient::class);
        $client->expects(self::never())->method('sendLogSync');
        $tester = $this->tester($client, enabled: true, endpoint: null, token: null);
        self::assertNotSame(0, $tester->execute([]));
        self::assertStringContainsString('not configured', $tester->getDisplay());
    }

    public function testReportsDeliveredOn202(): void
    {
        $client = $this->createMock(ApiClient::class);
        $client->expects(self::once())->method('sendLogSync')->willReturn(202);
        $tester = $this->tester($client, enabled: true, endpoint: 'https://l', token: 'sk');
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('delivered', strtolower($tester->getDisplay()));
    }

    public function testReportsRejectedOn401(): void
    {
        $client = $this->createMock(ApiClient::class);
        $client->expects(self::once())->method('sendLogSync')->willReturn(401);
        $tester = $this->tester($client, enabled: true, endpoint: 'https://l', token: 'sk');
        self::assertNotSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('401', $tester->getDisplay());
    }

    public function testReportsUnreachableOnNull(): void
    {
        $client = $this->createMock(ApiClient::class);
        $client->expects(self::once())->method('sendLogSync')->willReturn(null);
        $tester = $this->tester($client, enabled: true, endpoint: 'https://l', token: 'sk');
        self::assertNotSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('unreachable', strtolower($tester->getDisplay()));
    }

    public function testReportsMissingCredentialsForEmptyStringEndpoint(): void
    {
        $client = $this->createMock(ApiClient::class);
        $client->expects(self::never())->method('sendLogSync');
        $tester = $this->tester($client, enabled: true, endpoint: '', token: 'sk');
        self::assertNotSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('not configured', $tester->getDisplay());
    }

    public function testReportsMissingCredentialsForEmptyStringToken(): void
    {
        $client = $this->createMock(ApiClient::class);
        $client->expects(self::never())->method('sendLogSync');
        $tester = $this->tester($client, enabled: true, endpoint: 'https://l', token: '');
        self::assertNotSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('not configured', $tester->getDisplay());
    }
}
