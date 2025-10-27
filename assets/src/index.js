/**
 * Application Logger JavaScript SDK
 *
 * Captures JavaScript errors and sends them to the Application Logger platform.
 * Integrated with Symfony bundle for seamless error tracking.
 *
 * @module ApplicationLogger
 */

import { Client } from './client.js';
import { BreadcrumbCollector } from './breadcrumbs.js';
import { Transport } from './transport.js';
import { HeatmapTracker } from './heatmap.js';

/**
 * Main ApplicationLogger class
 */
class ApplicationLogger {
    /**
   * @param {Object} config Configuration options
   * @param {string} config.dsn Data Source Name (project endpoint URL)
   * @param {string} config.apiKey API Key for authentication
   * @param {string} [config.sessionId] Session ID for tracking (provided by server)
   * @param {string} [config.release] Application version/release
   * @param {string} [config.environment] Environment (production, staging, etc.)
   * @param {boolean} [config.debug=false] Enable debug logging
   * @param {boolean} [config.enableHeatmap=true] Enable heatmap click tracking
   * @param {number} [config.heatmapBatchSize=10] Heatmap batch size
   * @param {number} [config.heatmapBatchTimeout=5000] Heatmap batch timeout (ms)
   * @param {string[]} [config.scrubFields] Additional fields to scrub
   */
    constructor(config) {
        // Validate required configuration
        if (!config || !config.dsn) {
            throw new Error('ApplicationLogger: DSN is required. Expected format: https://host/project-id');
        }

        if (!config.apiKey) {
            throw new Error('ApplicationLogger: API Key is required for authentication');
        }

        this.config = {
            debug: false,
            scrubFields: ['password', 'token', 'api_key', 'secret'],
            enableHeatmap: true,
            heatmapBatchSize: 10,
            heatmapBatchTimeout: 5000,
            ...config,
        };

        this.transport = new Transport(this.config);
        this.breadcrumbs = new BreadcrumbCollector();
        this.client = new Client(this.config, this.transport, this.breadcrumbs);
        this.heatmap = new HeatmapTracker(this.transport, this.config);
        this.initialized = false;
    }

    /**
   * Initialize the SDK and start capturing errors
   */
    init() {
        if (this.initialized) {
            console.warn('ApplicationLogger already initialized');
            return;
        }

        this.client.install();

        // Install heatmap tracking if enabled and session ID is provided
        if (this.config.enableHeatmap && this.config.sessionId) {
            this.heatmap.install(this.config.sessionId);

            if (this.config.debug) {
                // eslint-disable-next-line no-console
                console.log('ApplicationLogger: Heatmap tracking enabled');
            }
        }

        this.initialized = true;

        if (this.config.debug) {
            // eslint-disable-next-line no-console
            console.log('ApplicationLogger initialized', this.config);
        }
    }

    /**
   * Manually capture an exception
   *
   * @param {Error} error The error to capture
   * @param {Object} [options] Additional options
   * @param {Object} [options.tags] Key-value tags
   * @param {Object} [options.extra] Additional context data
   */
    captureException(error, options = {}) {
        this.client.captureException(error, options);
    }

    /**
   * Manually capture a message
   *
   * @param {string} message The message to capture
   * @param {string} [level='info'] Log level
   * @param {Object} [options] Additional options
   */
    captureMessage(message, level = 'info', options = {}) {
        this.client.captureMessage(message, level, options);
    }

    /**
   * Add a breadcrumb
   *
   * @param {Object} breadcrumb Breadcrumb data
   * @param {string} breadcrumb.type Breadcrumb type (navigation, http, user, etc.)
   * @param {string} breadcrumb.category Category
   * @param {string} breadcrumb.message Message
   * @param {Object} [breadcrumb.data] Additional data
   * @param {string} [breadcrumb.level='info'] Log level
   */
    addBreadcrumb(breadcrumb) {
        this.breadcrumbs.add(breadcrumb);
    }

    /**
   * Set user context
   *
   * @param {Object} user User data
   * @param {string} [user.id] User ID
   * @param {string} [user.email] User email
   * @param {string} [user.username] Username
   */
    setUser(user) {
        this.client.setUser(user);
    }

    /**
   * Set tags
   *
   * @param {Object} tags Key-value tags
   */
    setTags(tags) {
        this.client.setTags(tags);
    }

    /**
   * Set extra context
   *
   * @param {Object} extra Key-value extra data
   */
    setExtra(extra) {
        this.client.setExtra(extra);
    }
}

// Export for ES modules
export default ApplicationLogger;

// Export for UMD (window.ApplicationLogger)
if (typeof window !== 'undefined') {
    window.ApplicationLogger = ApplicationLogger;
}
