<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\DependencyInjection;

use ApplicationLogger\Bundle\DependencyInjection\ApplicationLoggerExtension;
use ApplicationLogger\Bundle\EventSubscriber\ExceptionSubscriber;
use ApplicationLogger\Bundle\EventSubscriber\FlushTelemetrySubscriber;
use ApplicationLogger\Bundle\EventSubscriber\ScopeResetSubscriber;
use ApplicationLogger\Bundle\EventSubscriber\SessionTrackingSubscriber;
use ApplicationLogger\Bundle\Monolog\Handler\ApplicationLoggerHandler;
use ApplicationLogger\Bundle\Service\ApiClient;
use ApplicationLogger\Bundle\Service\Sdk\BundleContextCollector;
use ApplicationLogger\Bundle\Service\Sdk\LoopbackGuard;
use ApplicationLogger\Bundle\Service\Sdk\SdkClientFactory;
use ApplicationLogger\Bundle\Service\Sdk\SessionApiClient;
use ApplicationLogger\Sdk\CircuitBreaker;
use ApplicationLogger\Sdk\DataScrubber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Container-compile smoke test — the safety net that was missing when the latent
 * SessionApiClient breaker-wiring bug slipped in.
 *
 * Unlike a hand-rolled re-registration of the service graph, this test loads the
 * REAL config/services.yaml (via the bundle's ApplicationLoggerExtension::load(),
 * which uses YamlFileLoader) into a ContainerBuilder, compiles it, and then
 * FORCE-INSTANTIATES the key services through $container->get(). Force-instantiation
 * is the load-bearing step: it actually runs each service's constructor with the
 * wired arguments, so a type-mismatched injection surfaces as a PHP \TypeError.
 *
 * Concretely: SessionApiClient type-hints ApplicationLogger\Sdk\CircuitBreaker. If
 * services.yaml wired $breaker to the bundle's ApplicationLogger\Bundle\Service\
 * CircuitBreaker (the original bug), instantiating SessionApiClient here throws a
 * \TypeError. This test is coupled to the actual YAML, so it WOULD fail if that bug
 * recurred (verified by a revert sanity-check during development).
 *
 * symfony/yaml is a require-dev of the bundle precisely so this test can load the
 * real services.yaml standalone (the monorepo root also ships it).
 */
final class ContainerCompilesTest extends TestCase
{
    /**
     * Key services whose wiring this test guards. Marked public (via the compiler
     * pass below) so they survive RemoveUnusedDefinitionsPass and can be fetched.
     *
     * @var list<string>
     */
    private const KEY_SERVICES = [
        ApiClient::class,
        SdkClientFactory::class,
        SessionApiClient::class,
        LoopbackGuard::class,
        BundleContextCollector::class,
        ApplicationLoggerHandler::class,
        ExceptionSubscriber::class,
        SessionTrackingSubscriber::class,
        FlushTelemetrySubscriber::class,
        ScopeResetSubscriber::class,
        CircuitBreaker::class,
        DataScrubber::class,
    ];

    /**
     * Build a container with the REAL services.yaml loaded plus synthetic
     * definitions for the host-provided external services the bundle references.
     */
    private function buildContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();

        // Synthetic definitions for host-provided services. They have no factory
        // wiring; concrete instances are injected post-compile via $container->set().
        $container->setDefinition('request_stack', (new Definition(RequestStack::class))->setSynthetic(true));
        $container->setDefinition('cache.app', (new Definition(ArrayAdapter::class))->setSynthetic(true));
        $container->setDefinition('http_client', (new Definition(MockHttpClient::class))->setSynthetic(true));

        // Load the REAL services.yaml (+ commands.yaml) from the bundle.
        $extension = new ApplicationLoggerExtension();
        $extension->load(
            [['dsn' => 'https://applogger.eu/test-project', 'api_key' => 'pk_test']],
            $container,
        );

        // Force the guarded services public so they survive optimization and remain
        // fetchable. Runs BEFORE_REMOVING so it precedes RemoveUnusedDefinitionsPass.
        $container->addCompilerPass(
            new class(self::KEY_SERVICES) implements CompilerPassInterface {
                /** @param list<string> $serviceIds */
                public function __construct(private readonly array $serviceIds)
                {
                }

                public function process(ContainerBuilder $container): void
                {
                    foreach ($this->serviceIds as $id) {
                        if ($container->hasDefinition($id)) {
                            $container->getDefinition($id)->setPublic(true);
                        }
                    }
                }
            },
            PassConfig::TYPE_BEFORE_REMOVING,
        );

        return $container;
    }

    public function testContainerCompilesAndKeyServicesInstantiate(): void
    {
        $container = $this->buildContainer();

        // Compiling resolves every service reference + parameter placeholder.
        $container->compile();

        // Provide concrete instances for the synthetic host services so the guarded
        // services can actually be constructed.
        $container->set('request_stack', new RequestStack());
        $container->set('cache.app', new ArrayAdapter());
        $container->set('http_client', new MockHttpClient());

        // Force-instantiate each guarded service. This RUNS the constructors with the
        // wired arguments — the only thing that surfaces a type-mismatched injection
        // (e.g. the bundle CircuitBreaker handed to SessionApiClient → \TypeError).
        foreach (self::KEY_SERVICES as $id) {
            $service = $container->get($id);
            self::assertNotNull($service, \sprintf('Service "%s" must instantiate', $id));
        }

        // Spot-check the load-bearing instances are the expected concrete types.
        self::assertInstanceOf(SessionApiClient::class, $container->get(SessionApiClient::class));
        self::assertInstanceOf(CircuitBreaker::class, $container->get(CircuitBreaker::class));
        self::assertInstanceOf(ApiClient::class, $container->get(ApiClient::class));
    }

    public function testApiClientFacadeHasTwoArguments(): void
    {
        $container = $this->buildContainer();

        $def = $container->findDefinition(ApiClient::class);
        self::assertCount(
            2,
            $def->getArguments(),
            'ApiClient is now a @deprecated facade taking exactly ($factory, $sessions)',
        );
    }

    /**
     * Belt-and-suspenders alongside the instantiation test: the SessionApiClient
     * $breaker argument must reference sdk-core's CircuitBreaker, never the bundle's.
     */
    public function testSessionApiClientBreakerReferencesSdkCoreBreaker(): void
    {
        $container = $this->buildContainer();

        $args = $container->getDefinition(SessionApiClient::class)->getArguments();

        $breakerRef = null;
        foreach ($args as $arg) {
            if ($arg instanceof Reference && str_contains((string) $arg, 'CircuitBreaker')) {
                $breakerRef = (string) $arg;
            }
        }

        self::assertSame(
            CircuitBreaker::class,
            $breakerRef,
            'SessionApiClient $breaker must point to ApplicationLogger\Sdk\CircuitBreaker; '.
            'wiring it to the bundle CircuitBreaker is a runtime \TypeError.',
        );
    }

    public function testOrphanedLogBreakerServiceIsAbsent(): void
    {
        $container = $this->buildContainer();

        self::assertFalse(
            $container->hasDefinition('application_logger.circuit_breaker.log'),
            'The orphaned log circuit-breaker definition must be dropped',
        );
    }

    public function testOrphanedErrorPayloadFactoryDefinitionIsAbsent(): void
    {
        $container = $this->buildContainer();

        self::assertFalse(
            $container->hasDefinition('ApplicationLogger\\Bundle\\Service\\ErrorPayloadFactory'),
            'The orphaned ErrorPayloadFactory service definition must be absent (class deleted in Task 12)',
        );
    }
}
