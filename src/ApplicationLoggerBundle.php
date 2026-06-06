<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle;

use ApplicationLogger\Bundle\DependencyInjection\ApplicationLoggerExtension;
use ApplicationLogger\Bundle\DependencyInjection\Compiler\RemoveTwigServicesPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * ApplicationLogger Symfony Bundle.
 *
 * Provides error tracking and logging integration with the Application Logger platform.
 * Designed with resilience as the top priority - never affects host application performance.
 */
class ApplicationLoggerBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Drop the Twig-based JS SDK auto-injection when the host has no Twig, so API-only
        // apps install cleanly. Runs as a compiler pass because Twig's presence is only
        // reliably known after all extensions have loaded.
        $container->addCompilerPass(new RemoveTwigServicesPass());
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        return new ApplicationLoggerExtension();
    }
}
