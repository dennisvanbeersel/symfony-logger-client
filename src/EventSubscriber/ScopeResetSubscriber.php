<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\EventSubscriber;

use ApplicationLogger\Sdk\Hub;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Resets the sdk-core per-request Scope at the START of every main request. CRITICAL in
 * FrankenPHP worker mode: the static Hub + mutable Scope persist across requests, so
 * without this, request N's user/tags/breadcrumbs bleed into request N+1 (PII leak +
 * misattribution). High priority (4096) so it runs before any enrichment. Total.
 *
 * The Scope is only ever mutated immediately before a capture (ExceptionSubscriber),
 * which builds the Hub, so Hub::getCurrent() is non-null whenever there is anything to
 * reset; a reset before any build is a harmless no-op.
 */
final class ScopeResetSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 4096]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        try {
            Hub::getCurrent()?->resetScope();
        } catch (\Throwable) {
            // never throw into the host
        }
    }
}
