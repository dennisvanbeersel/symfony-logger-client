<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\DependencyInjection;

use ApplicationLogger\Bundle\DependencyInjection\ApplicationLoggerExtension;
use ApplicationLogger\Bundle\Twig\ApplicationLoggerExtension as TwigExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ServicesWiringTest extends TestCase
{
    public function testTwigExtensionConfigCarriesPublicKeyNotApiKey(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.cache_dir', sys_get_temp_dir());
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        (new ApplicationLoggerExtension())->load([[]], $container);

        $def = $container->getDefinition(TwigExtension::class);
        /** @var array<string, mixed> $config */
        $config = $def->getArgument('$config');

        self::assertArrayHasKey('publishable_key', $config);
        self::assertSame('%application_logger.publishable_key%', $config['publishable_key']);
        self::assertArrayNotHasKey('api_key', $config);
    }
}
