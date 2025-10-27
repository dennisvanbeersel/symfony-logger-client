<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle;

use ApplicationLogger\Bundle\DependencyInjection\ApplicationLoggerExtension;
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

    public function getContainerExtension(): ?ExtensionInterface
    {
        return new ApplicationLoggerExtension();
    }
}
