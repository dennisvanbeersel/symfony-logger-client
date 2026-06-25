<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\EventSubscriber;

use ApplicationLogger\Bundle\Service\Sdk\SdkClientFactory;
use ApplicationLogger\Bundle\Service\Sdk\SessionClientInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Drives pending async telemetry transfers to completion AFTER the HTTP response
 * has been flushed to the client.
 *
 * THE PROBLEM THIS SOLVES
 * -----------------------
 * In async mode, the sdk-core transport initiates a fire-and-forget POST and
 * performs ONE zero-timeout poll. If the response is still in flight after that
 * poll, it is retained and the cURL transfer is expected to progress on subsequent
 * polls. In a per-request SAPI (PHP-FPM, PHP built-in server, FrankenPHP
 * non-worker mode), nothing polls the transfer again before end-of-request, so
 * the handle is cancelled — aborting the POST before it is transmitted and
 * silently losing the telemetry event.
 *
 * THE FIX
 * -------
 * `kernel.terminate` fires AFTER Symfony has sent the response to the client
 * (via `fastcgi_finish_request()` in FPM, or an equivalent runtime flush in
 * FrankenPHP and the built-in server). Blocking briefly here to drain the pending
 * cURL handles does NOT delay the user-visible response. This subscriber flushes
 * all three telemetry pipelines — logs (LogClient), errors (Client), and sessions
 * (SessionApiClient) — each in its own independent try/catch so one failing flush
 * never skips the others.
 *
 * CLI commands and Messenger consumers have no `kernel.terminate` event; they rely
 * on the bounded `__destruct()` call instead (sdk-core transport handles this).
 *
 * PRIORITY
 * --------
 * Priority -1024 ensures this runs last, after all other terminate work (session
 * commit, profiler flush, etc.) that may itself generate telemetry we still want
 * to capture.
 */
final readonly class FlushTelemetrySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private SdkClientFactory $factory,
        private SessionClientInterface $sessions,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => ['onKernelTerminate', -1024],
        ];
    }

    /**
     * Called after the response has been sent. Blocking here is safe because the
     * user no longer waits for this process.
     *
     * Independent best-effort flushes — one failing must never skip the others,
     * and none may throw into the host (Rule #1). Logs first (match prior intent),
     * then errors, then sessions. getHub() builds lazily and may throw → guard it;
     * sessions flush even if the Hub is unavailable (SessionApiClient is Hub-independent).
     */
    public function onKernelTerminate(TerminateEvent $_event): void
    {
        $hub = null;
        try {
            $hub = $this->factory->getHub();
        } catch (\Throwable) {
            // Hub build failed; log/error flushes skipped but session flush still runs.
        }

        try {
            $hub?->getLogClient()?->flush();
        } catch (\Throwable) {
            // Best-effort; never let a log-flush error affect the host or block subsequent flushes.
        }

        try {
            $hub?->getClient()->flush();
        } catch (\Throwable) {
            // Best-effort; never let an error-flush error affect the host or block sessions.
        }

        try {
            $this->sessions->flush();
        } catch (\Throwable) {
            // Best-effort; never let a session-flush error affect the host.
        }
    }
}
