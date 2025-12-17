import { CircuitBreaker } from './circuit-breaker.js';
import { StorageQueue } from './storage-queue.js';
import { RateLimiter } from './rate-limiter.js';

/**
 * Transport layer for sending errors to the platform
 *
 * RESILIENCE FEATURES:
 * - 3-second timeout with AbortController
 * - Circuit breaker prevents repeated calls to failing service
 * - Smart retry with exponential backoff
 * - Local storage queue for offline errors
 * - Rate limiting to prevent error storms
 * - Deduplication to avoid duplicate errors
 */
export class Transport {
    constructor(config) {
        this.config = config;
        this.apiKey = config.apiKey; // Store API key separately (not in DSN)
        this.dsn = this.parseDsn(config.dsn);
        this.queue = [];
        this.sending = false;

        // Initialize resilience components (configurable via SDK config)
        this.circuitBreaker = new CircuitBreaker({
            failureThreshold: config.circuitBreakerFailureThreshold ?? 5,
            timeout: config.circuitBreakerTimeoutMs ?? 60000,
        });

        this.storageQueue = new StorageQueue({
            maxSize: config.storageQueueMaxSize ?? 50,
            maxAge: config.storageQueueMaxAgeMs ?? 86400000,
        });

        this.rateLimiter = new RateLimiter({
            maxTokens: config.rateLimiterMaxTokens ?? 10,
            refillRate: config.rateLimiterRefillRate ?? 0.167,
        });

        // Deduplication cache (configurable via SDK config)
        this.recentErrors = new Map();
        this.deduplicationWindow = config.deduplicationWindowMs ?? 5000;

        // Try to flush stored errors on init
        this.flushStoredErrors();
    }

    /**
   * Parse DSN into components
   *
   * DSN format: {protocol}://{host}/{projectId}
   * Example: https://localhost:8111/b6d8ed85-c0af-4c02-b6bb-bfb0f3609b37
   *
   * Note: API key is NOT in the DSN. It's passed separately via config.apiKey.
   */
    parseDsn(dsn) {
        if (!dsn) {
            throw new Error('DSN is required');
        }

        try {
            const url = new URL(dsn);
            const projectId = url.pathname.replace(/^\//, ''); // Remove leading slash

            if (!projectId) {
                throw new Error('DSN must include a project ID in the path');
            }

            return {
                protocol: url.protocol.replace(':', ''),
                host: url.host,
                projectId: projectId,
                endpoint: `${url.protocol}//${url.host}/api/errors/ingest`,
            };
        } catch (error) {
            throw new Error(`Invalid DSN format: ${error.message}. Expected: https://host/project-id`);
        }
    }

    /**
   * Send error payload to platform
   *
   * @param {Object} payload - Error payload
   * @param {Object|null} replayData - Optional session replay data
   * @param {string} replayData.sessionId - Session ID from SessionManager
   * @param {Array} replayData.events - Buffered events (before + after error)
   */
    async send(payload, replayData = null) {
        try {
            // Merge replay data into payload if provided
            let enhancedPayload = payload;
            if (replayData) {
                enhancedPayload = {
                    ...payload,
                    replay_session_id: replayData.sessionId,
                    replay_data: replayData.events,
                };

                if (this.config.debug) {
                    console.warn('ApplicationLogger: Sending error with replay data', {
                        sessionId: replayData.sessionId,
                        eventCount: replayData.events?.length || 0,
                    });
                }
            }

            // Scrub sensitive data
            const scrubbedPayload = this.scrubSensitiveData(enhancedPayload);

            // Check for duplicates
            if (this.isDuplicate(scrubbedPayload)) {
                if (this.config.debug) {
                    console.warn('ApplicationLogger: Duplicate error ignored');
                }
                return;
            }

            // Check rate limit
            if (!this.rateLimiter.consume()) {
                if (this.config.debug) {
                    console.warn('ApplicationLogger: Rate limit exceeded, error queued');
                }
                this.storageQueue.enqueue(scrubbedPayload);
                return;
            }

            // Add to queue
            this.queue.push(scrubbedPayload);

            // Process queue
            if (!this.sending) {
                await this.processQueue();
            }
        } catch (error) {
            // Never crash on send errors
            console.error('ApplicationLogger: Send failed', error);
        }
    }

    /**
     * Send recovery session (Phase 2 of two-phase session replay)
     *
     * This is sent separately after the initial error + pre-error data.
     * Recovery sessions show user actions AFTER the error occurred.
     *
     * @param {Object} recoveryPayload - Recovery session payload
     * @param {string} recoveryPayload.sessionId - Session ID
     * @param {Array} recoveryPayload.events - Recovery events (after error)
     * @param {string} [recoveryPayload.capturedAt] - ISO 8601 timestamp
     * @param {string} [recoveryPayload.url] - Current URL
     * @param {boolean} [useBeacon=false] - Use sendBeacon API for reliable unload transmission
     */
    async sendRecoverySession(recoveryPayload, useBeacon = false) {
        try {
            // Use dedicated recovery session endpoint
            const endpoint = `${this.dsn.protocol}://${this.dsn.host}/api/errors/recovery-session`;

            if (this.config.debug) {
                console.warn('ApplicationLogger: Sending recovery session', {
                    sessionId: recoveryPayload.sessionId,
                    eventCount: recoveryPayload.events?.length || 0,
                    method: useBeacon ? 'sendBeacon' : 'fetch',
                });
            }

            // Use sendBeacon for page unload (synchronous, guaranteed delivery)
            if (useBeacon && navigator.sendBeacon) {
                // sendBeacon cannot send custom headers, so include API key in body
                const payloadWithAuth = {
                    ...recoveryPayload,
                    apiKey: this.apiKey,
                };

                const blob = new Blob([JSON.stringify(payloadWithAuth)], {
                    type: 'application/json',
                });

                const sent = navigator.sendBeacon(endpoint, blob);

                if (sent) {
                    if (this.config.debug) {
                        console.warn('ApplicationLogger: Recovery session queued via sendBeacon');
                    }
                    return { success: true, method: 'beacon' };
                } else {
                    throw new Error('sendBeacon failed (queue full or too large)');
                }
            }

            // Fallback to fetch (normal case)
            // Create AbortController for timeout
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 5000); // 5-second timeout (longer for recovery)

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Api-Key': this.apiKey,
                    'User-Agent': 'ApplicationLogger-JS-SDK/1.0',
                },
                body: JSON.stringify(recoveryPayload),
                signal: controller.signal,
            });

            clearTimeout(timeoutId);

            if (!response.ok) {
                throw new Error(`Recovery session send failed: ${response.status}`);
            }

            if (this.config.debug) {
                console.warn('ApplicationLogger: Recovery session sent successfully');
            }

            return response.json();
        } catch (error) {
            console.error('ApplicationLogger: Failed to send recovery session', error);

            // Store in queue for retry (best effort)
            try {
                this.storageQueue.enqueue({
                    type: 'recovery',
                    payload: recoveryPayload,
                });
            } catch (queueError) {
                console.error('ApplicationLogger: Failed to queue recovery session', queueError);
            }

            throw error;
        }
    }

    /**
   * Process queued errors
   */
    async processQueue() {
        if (this.queue.length === 0 || this.sending) {
            return;
        }

        this.sending = true;

        while (this.queue.length > 0) {
            const payload = this.queue.shift();

            try {
                await this.sendToApi(payload);

                if (this.config.debug) {
                    console.warn('ApplicationLogger: Error sent successfully');
                }
            } catch {
                // Error already handled in sendToApi
                // Don't re-queue here as sendToApi handles storage
            }
        }

        this.sending = false;
    }

    /**
   * Send payload to API with timeout and retry
   */
    async sendToApi(payload, attempt = 0) {
        // Check circuit breaker
        if (this.circuitBreaker.isOpen()) {
            if (this.config.debug) {
                console.warn('ApplicationLogger: Circuit breaker is open, error queued to storage');
            }
            this.storageQueue.enqueue(payload);
            return;
        }

        // Create AbortController for timeout
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 3000); // 3-second timeout

        try {
            const response = await fetch(this.dsn.endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Api-Key': this.apiKey, // Use separate API key, not from DSN
                    'User-Agent': 'ApplicationLogger-JS-SDK/1.0',
                },
                body: JSON.stringify(payload),
                signal: controller.signal,
            });

            clearTimeout(timeoutId);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            // Success!
            this.circuitBreaker.recordSuccess();

            // Try to flush stored errors on success
            this.flushStoredErrors();

            return response.json();
        } catch (error) {
            clearTimeout(timeoutId);

            // Handle timeout
            if (error.name === 'AbortError') {
                this.circuitBreaker.recordFailure();

                if (this.config.debug) {
                    console.error('ApplicationLogger: Request timeout');
                }

                this.storageQueue.enqueue(payload);
                return;
            }

            // Handle network errors with retry
            if (attempt < 2) {
                // Exponential backoff: 1s, 2s
                const delay = Math.pow(2, attempt) * 1000;
                await this.delay(delay);

                return this.sendToApi(payload, attempt + 1);
            }

            // Max retries reached
            this.circuitBreaker.recordFailure();

            if (this.config.debug) {
                console.error('ApplicationLogger: Max retries reached', error);
            }

            this.storageQueue.enqueue(payload);
        }
    }

    /**
   * Check if error is a duplicate
   *
   * Payload uses flat structure (not nested exception object):
   * {type, message, file, line, stack_trace, ...}
   */
    isDuplicate(payload) {
        try {
            // Create hash from error signature (flat payload structure)
            const signature = JSON.stringify({
                type: payload.type,
                message: payload.message,
                stack: payload.stack_trace?.slice(0, 3), // Top 3 frames
            });

            const hash = this.simpleHash(signature);

            // Check if we've seen this recently
            if (this.recentErrors.has(hash)) {
                return true;
            }

            // Add to recent errors
            this.recentErrors.set(hash, Date.now());

            // Clean up old entries
            const now = Date.now();
            for (const [key, timestamp] of this.recentErrors) {
                if (now - timestamp > this.deduplicationWindow) {
                    this.recentErrors.delete(key);
                }
            }

            return false;
        } catch {
            return false; // If deduplication fails, allow the error through
        }
    }

    /**
   * Simple hash function
   */
    simpleHash(str) {
        let hash = 0;
        for (let i = 0; i < str.length; i++) {
            const char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash; // Convert to 32bit integer
        }
        return hash.toString();
    }

    /**
   * Flush errors from storage queue
   */
    async flushStoredErrors() {
        try {
            const queueSize = this.storageQueue.size();

            if (queueSize === 0) {
                return;
            }

            if (this.config.debug) {
                console.warn(`ApplicationLogger: Flushing ${queueSize} stored errors`);
            }

            // Limit flush to 5 errors at a time to avoid overwhelming
            const limit = Math.min(queueSize, 5);

            for (let i = 0; i < limit; i++) {
                const payload = this.storageQueue.dequeue();

                if (payload) {
                    // Add to queue (but don't recurse infinitely)
                    this.queue.push(payload);
                }
            }

            // Process the queue
            if (!this.sending && this.queue.length > 0) {
                await this.processQueue();
            }
        } catch (error) {
            // Never crash on flush
            if (this.config.debug) {
                console.error('ApplicationLogger: Flush failed', error);
            }
        }
    }

    /**
   * Delay helper for retry backoff
   */
    delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    /**
   * Scrub sensitive data from payload
   */
    scrubSensitiveData(payload) {
        const scrubFields = this.config.scrubFields || [];
        const scrubPatterns = [
            ...scrubFields,
            'password',
            'passwd',
            'pwd',
            'secret',
            'api_key',
            'apikey',
            'token',
            'auth',
            'authorization',
            'private_key',
            'access_token',
            'refresh_token',
        ];

        // Deep clone payload (handle circular references)
        let scrubbed;
        try {
            scrubbed = JSON.parse(JSON.stringify(payload));
        } catch {
            // Circular reference detected - work with original and remove circular refs
            scrubbed = this.removeCircularReferences(payload);
        }

        // Recursively scrub object
        const scrubObject = (obj) => {
            if (!obj || typeof obj !== 'object') {
                return obj;
            }

            for (const key in obj) {
                if (Object.prototype.hasOwnProperty.call(obj, key)) {
                    // Check if key matches scrub pattern
                    const shouldScrub = scrubPatterns.some(pattern =>
                        key.toLowerCase().includes(pattern.toLowerCase()),
                    );

                    if (shouldScrub) {
                        obj[key] = '[REDACTED]';
                    } else if (typeof obj[key] === 'object') {
                        scrubObject(obj[key]);
                    }
                }
            }

            return obj;
        };

        return scrubObject(scrubbed);
    }

    /**
     * Remove circular references from an object.
     * Uses a WeakSet to track visited objects and prevent infinite loops.
     *
     * @param {Object} obj - Object to process
     * @param {WeakSet} [seen] - Set of already visited objects
     * @returns {Object} Object with circular references replaced
     */
    removeCircularReferences(obj, seen = new WeakSet()) {
        // Handle primitives and null
        if (obj === null || typeof obj !== 'object') {
            return obj;
        }

        // Handle circular reference
        if (seen.has(obj)) {
            return '[Circular Reference]';
        }

        // Add to seen set
        seen.add(obj);

        // Handle arrays
        if (Array.isArray(obj)) {
            return obj.map(item => this.removeCircularReferences(item, seen));
        }

        // Handle objects
        const result = {};
        for (const key in obj) {
            if (Object.prototype.hasOwnProperty.call(obj, key)) {
                try {
                    result[key] = this.removeCircularReferences(obj[key], seen);
                } catch {
                    // Skip properties that throw errors when accessed
                    result[key] = '[Error accessing property]';
                }
            }
        }

        return result;
    }

    /**
     * Send session event to API
     */
    async sendSessionEvent(sessionId, eventData) {
        if (!sessionId || !eventData) {
            return;
        }

        try {
            const url = `${this.dsn.protocol}://${this.dsn.host}/api/v1/sessions/${sessionId}/events`;

            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Api-Key': this.apiKey,
                    'User-Agent': 'ApplicationLogger-JS-SDK/1.0',
                },
                body: JSON.stringify(eventData),
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            return response.json();
        } catch (error) {
            // Silently fail - session tracking is non-critical
            if (this.config.debug) {
                console.error('ApplicationLogger: Failed to send session event', error);
            }
        }
    }

    /**
     * Send session replay click data to API (batch)
     */
    async sendReplayClicks(sessionId, clicks) {
        if (!sessionId || !clicks || clicks.length === 0) {
            return;
        }

        try {
            const url = `${this.dsn.protocol}://${this.dsn.host}/api/v1/sessions/${sessionId}/replay`;

            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Api-Key': this.apiKey,
                    'User-Agent': 'ApplicationLogger-JS-SDK/1.0',
                },
                body: JSON.stringify({ clicks }),
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            if (this.config.debug) {
                console.warn(`ApplicationLogger: Sent ${clicks.length} heatmap clicks`);
            }

            return response.json();
        } catch (error) {
            // Silently fail - heatmap tracking is non-critical
            if (this.config.debug) {
                console.error('ApplicationLogger: Failed to send heatmap data', error);
            }
        }
    }

    /**
   * Get stats for monitoring
   */
    getStats() {
        return {
            queueSize: this.queue.length,
            storedErrors: this.storageQueue.size(),
            circuitBreaker: this.circuitBreaker.getState(),
            rateLimitTokens: this.rateLimiter.getTokens(),
        };
    }

    /**
   * Flush pending errors using Beacon API
   * Called on page unload to ensure errors are sent even as page closes
   */
    flushWithBeacon() {
        try {
            // Get all stored errors (from offline queue)
            const storedErrors = this.storageQueue.getAll();

            // Also include current queue
            const allErrors = [...this.queue, ...storedErrors];

            if (allErrors.length === 0) {
                return;
            }

            // Limit to 10 most recent errors to avoid payload size issues
            const errorsToSend = allErrors.slice(-10);

            // Beacon API has limitations with headers, so we include DSN in body
            const beaconPayload = {
                dsn: this.config.dsn,
                errors: errorsToSend,
            };

            const blob = new Blob([JSON.stringify(beaconPayload)], {
                type: 'application/json',
            });

            // Try to send via Beacon API
            const sent = navigator.sendBeacon(this.dsn.endpoint, blob);

            if (sent) {
                // Successfully queued for sending
                // Clear the storage queue and current queue
                this.storageQueue.clear();
                this.queue = [];

                if (this.config.debug) {
                    console.warn(`ApplicationLogger: Flushed ${errorsToSend.length} errors via Beacon API`);
                }
            }
        } catch (error) {
            // Beacon flush failed - errors remain in storage for next session
            if (this.config.debug) {
                console.error('ApplicationLogger: Beacon flush failed', error);
            }
        }
    }
}
