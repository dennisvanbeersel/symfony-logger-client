<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\EventSubscriber;

use ApplicationLogger\Bundle\Monolog\Handler\ApplicationLoggerHandler;
use ApplicationLogger\Bundle\Service\ApiClient;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Drives pending async telemetry transfers to completion AFTER the HTTP response
 * has been flushed to the client.
 *
 * THE PROBLEM THIS SOLVES
 * -----------------------
 * In async mode, {@see ResilientHttpDispatcher::post()} initiates a fire-and-forget
 * POST and performs ONE zero-timeout poll (`stream($response, 0.0)`). If the
 * response is still in flight after that poll, it is retained in `$pendingResponses`
 * and the cURL transfer is expected to progress on subsequent polls. In a
 * per-request SAPI (PHP-FPM, PHP built-in server, FrankenPHP non-worker mode),
 * nothing polls the transfer again before end-of-request, so `__destruct()` runs
 * `cancel()` — aborting the POST before it is transmitted and silently losing the
 * telemetry event.
 *
 * THE FIX
 * -------
 * `kernel.terminate` fires AFTER Symfony has sent the response to the client
 * (via `fastcgi_finish_request()` in FPM, or an equivalent runtime flush in
 * FrankenPHP and the built-in server). Blocking briefly here to drain the pending
 * cURL handles does NOT delay the user-visible response. This subscriber calls
 * `ApiClient::flush()` → `ResilientHttpDispatcher::flushAndComplete()`, which loops
 * `stream()` until every in-flight handle reaches `isLast()` or the configured
 * timeout expires, recording a circuit-breaker outcome either way.
 *
 * CLI commands and Messenger consumers have no `kernel.terminate` event; they rely
 * on the bounded `__destruct()` call instead (see `ResilientHttpDispatcher`).
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
        private ApiClient $apiClient,
        private ?ApplicationLoggerHandler $logHandler = null,
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
     */
    public function onKernelTerminate(TerminateEvent $_event): void
    {
        // INDEPENDENT try/catch per flush: a throw from the log-handler flush must NOT
        // skip the apiClient flush (which drains in-flight error/session POSTs). Wrapping
        // both in one try/catch meant an early failure in flushLogs() silently dropped
        // the pending error transfers. Each flush is best-effort and self-contained;
        // either failing never affects the host app (CRITICAL: never throw into the host).

        // Flush buffered log-aggregation records FIRST, at this controlled post-response
        // point, so the log path gets the same deferred, circuit-breaker-gated,
        // bounded-timeout delivery as the error path.
        //
        // Previously the handler only flushed its buffer in __destruct (PHP shutdown).
        // That ran AFTER kernel.terminate, when the cache pool may already be torn down —
        // so circuit-breaker failures recorded for a slow/unreachable collector did NOT
        // persist, the breaker never opened, and every request that shipped logs kept
        // paying the full per-flush timeout. Draining here (cache still live) lets the
        // breaker shed load, and on real FPM/FrankenPHP this runs after the response is
        // flushed.
        try {
            $this->logHandler?->flushLogs();
        } catch (\Throwable) {
            // Best-effort; never let a log-flush error affect the host or block the
            // apiClient flush below.
        }

        try {
            $this->apiClient->flush();
        } catch (\Throwable) {
            // Best-effort; never let an error/session-flush error affect the host.
        }
    }
}
