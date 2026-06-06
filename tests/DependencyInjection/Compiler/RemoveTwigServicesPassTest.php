<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\DependencyInjection\Compiler;

use ApplicationLogger\Bundle\DependencyInjection\Compiler\RemoveTwigServicesPass;
use ApplicationLogger\Bundle\EventSubscriber\JavaScriptInjectionSubscriber;
use ApplicationLogger\Bundle\Twig\ApplicationLoggerExtension as TwigExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class RemoveTwigServicesPassTest extends TestCase
{
    private function containerWithBundleTwigServices(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(TwigExtension::class, new Definition(TwigExtension::class));
        $container->setDefinition(JavaScriptInjectionSubscriber::class, new Definition(JavaScriptInjectionSubscriber::class));

        return $container;
    }

    public function testRemovesTwigServicesWhenTwigAbsent(): void
    {
        $container = $this->containerWithBundleTwigServices();
        // no 'twig' service defined

        (new RemoveTwigServicesPass())->process($container);

        self::assertFalse($container->hasDefinition(TwigExtension::class));
        self::assertFalse($container->hasDefinition(JavaScriptInjectionSubscriber::class));
    }

    public function testKeepsTwigServicesWhenTwigPresent(): void
    {
        $container = $this->containerWithBundleTwigServices();
        $container->setDefinition('twig', new Definition(\stdClass::class));

        (new RemoveTwigServicesPass())->process($container);

        self::assertTrue($container->hasDefinition(TwigExtension::class));
        self::assertTrue($container->hasDefinition(JavaScriptInjectionSubscriber::class));
    }
}
