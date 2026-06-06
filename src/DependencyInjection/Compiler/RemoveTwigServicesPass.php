<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\DependencyInjection\Compiler;

use ApplicationLogger\Bundle\EventSubscriber\JavaScriptInjectionSubscriber;
use ApplicationLogger\Bundle\Twig\ApplicationLoggerExtension as TwigExtension;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Removes the Twig-dependent services (the JS SDK auto-injection) when the host application
 * has no Twig.
 *
 * Twig is an OPTIONAL integration of this bundle (declared under require-dev), so an API-only
 * / Twig-less host app must still install and compile cleanly. We cannot decide this in the
 * Extension's load() because `ContainerBuilder::hasExtension('twig')` is not reliable there;
 * a compiler pass runs AFTER every bundle's extension has loaded, so the `twig` service is
 * present iff TwigBundle is actually active.
 */
final class RemoveTwigServicesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->has('twig')) {
            return;
        }

        foreach ([TwigExtension::class, JavaScriptInjectionSubscriber::class] as $serviceId) {
            if ($container->hasDefinition($serviceId)) {
                $container->removeDefinition($serviceId);
            }
        }
    }
}
