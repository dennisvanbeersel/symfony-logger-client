/**
 * DOM Serializer - Privacy-First Visual Structure Capture
 *
 * CRITICAL: This module captures ONLY visual DOM structure for session replay.
 * It is designed with privacy as the #1 priority.
 *
 * ✅ WHAT IS CAPTURED:
 * - Element tag names (div, button, input, etc.)
 * - Bounding rectangles (x, y, width, height)
 * - Background colors (computed styles)
 * - Layout types (flex, grid, block, inline)
 * - Parent-child relationships (tree structure)
 *
 * ❌ WHAT IS NEVER CAPTURED:
 * - Text content (NO textContent, innerText, innerHTML)
 * - Attribute values (NO id, class, href, src, data-*)
 * - Form values (NO input values, textarea, select options)
 * - User-generated content (NO comments, user text)
 * - Sensitive styles (NO font-family, content properties)
 * - URLs or file paths (NO src, href, background-image)
 *
 * PRIVACY GUARANTEE:
 * All data is sanitized client-side. The server receives ONLY:
 * - Visual block structure (colored rectangles)
 * - Element types (for rendering context)
 * - Layout information (for accurate replay)
 *
 * This allows session replay visualization without exposing any user data.
 */
export class DOMSerializer {
    constructor(options = {}) {
        this.maxDepth = options.maxDepth || 10; // Prevent deep recursion
        this.minSize = options.minSize || 5; // Skip tiny elements (px)
        this.skipInvisible = options.skipInvisible !== false; // Skip hidden elements
        this.captureColors = options.captureColors !== false; // Capture bg colors
        this.debug = options.debug || false;

        // Performance tracking
        this.stats = {
            totalElements: 0,
            skippedInvisible: 0,
            skippedTiny: 0,
            skippedNonVisual: 0,
            maxDepthReached: 0,
        };
    }

    /**
     * Serialize the current DOM tree to a privacy-safe structure.
     *
     * @param {Element} [rootElement=document.body] - Root element to serialize
     * @returns {Object} Serialized DOM structure
     */
    serialize(rootElement = document.body) {
        // Reset stats
        this.stats = {
            totalElements: 0,
            skippedInvisible: 0,
            skippedTiny: 0,
            skippedNonVisual: 0,
            maxDepthReached: 0,
        };

        const startTime = performance.now();

        try {
            // Get viewport dimensions for context
            const viewport = {
                width: window.innerWidth,
                height: window.innerHeight,
                scrollX: window.scrollX,
                scrollY: window.scrollY,
            };

            // Serialize the tree
            const tree = this.serializeElement(rootElement, 0);

            const elapsed = performance.now() - startTime;

            if (this.debug) {
                console.warn('DOM Serialization Stats:', {
                    ...this.stats,
                    elapsedMs: elapsed.toFixed(2),
                });
            }

            return {
                viewport,
                tree,
                timestamp: Date.now(),
                stats: this.stats,
            };
        } catch (error) {
            console.error('DOM serialization failed:', error);
            return null;
        }
    }

    /**
     * Serialize a single element and its children recursively.
     *
     * @private
     * @param {Element} element - Element to serialize
     * @param {number} depth - Current recursion depth
     * @returns {Object|null} Serialized element or null if skipped
     */
    serializeElement(element, depth) {
        // Check depth limit
        if (depth >= this.maxDepth) {
            this.stats.maxDepthReached++;
            return null;
        }

        // Skip non-visual elements
        if (this.isNonVisualElement(element)) {
            this.stats.skippedNonVisual++;
            return null;
        }

        // Get computed style (for visibility and color)
        const style = window.getComputedStyle(element);

        // Skip invisible elements
        if (this.skipInvisible && this.isInvisible(element, style)) {
            this.stats.skippedInvisible++;
            return null;
        }

        // Get bounding rectangle
        const rect = element.getBoundingClientRect();

        // Skip tiny elements (noise)
        if (rect.width < this.minSize || rect.height < this.minSize) {
            this.stats.skippedTiny++;
            return null;
        }

        this.stats.totalElements++;

        // Build serialized element
        const serialized = {
            // Element type (tag name)
            type: element.tagName.toLowerCase(),

            // Bounding box (relative to viewport)
            bounds: {
                x: Math.round(rect.left + window.scrollX),
                y: Math.round(rect.top + window.scrollY),
                width: Math.round(rect.width),
                height: Math.round(rect.height),
            },

            // Visual properties
            bgColor: this.captureColors ? this.extractBackgroundColor(style) : null,
            layout: this.detectLayoutType(style),

            // Meta information (for better rendering)
            isInteractive: this.isInteractive(element),
            isText: this.isTextContainer(element),
        };

        // Serialize children recursively
        const children = [];
        const childElements = Array.from(element.children);

        for (const child of childElements) {
            const serializedChild = this.serializeElement(child, depth + 1);
            if (serializedChild) {
                children.push(serializedChild);
            }
        }

        if (children.length > 0) {
            serialized.children = children;
        }

        return serialized;
    }

    /**
     * Check if element is non-visual (script, style, etc.).
     *
     * @private
     * @param {Element} element
     * @returns {boolean}
     */
    isNonVisualElement(element) {
        const nonVisualTags = [
            'SCRIPT',
            'STYLE',
            'LINK',
            'META',
            'NOSCRIPT',
            'TITLE',
            'HEAD',
            'BASE',
        ];

        return nonVisualTags.includes(element.tagName);
    }

    /**
     * Check if element is invisible (display:none, visibility:hidden, etc.).
     *
     * @private
     * @param {Element} element
     * @param {CSSStyleDeclaration} style - Computed style
     * @returns {boolean}
     */
    isInvisible(element, style) {
        // Display none
        if (style.display === 'none') {
            return true;
        }

        // Visibility hidden
        if (style.visibility === 'hidden') {
            return true;
        }

        // Fully transparent
        if (parseFloat(style.opacity) === 0) {
            return true;
        }

        // Outside viewport (way off-screen)
        const rect = element.getBoundingClientRect();
        if (
            rect.bottom < -1000 ||
            rect.top > window.innerHeight + 1000 ||
            rect.right < -1000 ||
            rect.left > window.innerWidth + 1000
        ) {
            return true;
        }

        return false;
    }

    /**
     * Extract background color from computed style (privacy-safe).
     *
     * @private
     * @param {CSSStyleDeclaration} style
     * @returns {string|null} Hex color or null
     */
    extractBackgroundColor(style) {
        try {
            const bgColor = style.backgroundColor;

            // Skip transparent
            if (!bgColor || bgColor === 'transparent' || bgColor === 'rgba(0, 0, 0, 0)') {
                return null;
            }

            // Convert to hex (simpler storage)
            return this.rgbToHex(bgColor);
        } catch {
            return null;
        }
    }

    /**
     * Convert RGB/RGBA color to hex format.
     *
     * @private
     * @param {string} rgb - RGB/RGBA color string
     * @returns {string|null} Hex color
     */
    rgbToHex(rgb) {
        try {
            // Match rgb(r, g, b) or rgba(r, g, b, a)
            const match = rgb.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*[\d.]+)?\)/);
            if (!match) {
                return null;
            }

            const r = parseInt(match[1], 10);
            const g = parseInt(match[2], 10);
            const b = parseInt(match[3], 10);

            // Convert to hex
            const toHex = (n) => {
                const hex = n.toString(16);
                return hex.length === 1 ? '0' + hex : hex;
            };

            return `#${toHex(r)}${toHex(g)}${toHex(b)}`;
        } catch {
            return null;
        }
    }

    /**
     * Detect layout type (flex, grid, block, inline).
     *
     * @private
     * @param {CSSStyleDeclaration} style
     * @returns {string}
     */
    detectLayoutType(style) {
        const display = style.display;

        if (display.includes('flex')) {
            return 'flex';
        }

        if (display.includes('grid')) {
            return 'grid';
        }

        if (display === 'inline' || display === 'inline-block') {
            return 'inline';
        }

        return 'block';
    }

    /**
     * Check if element is interactive (button, link, input, etc.).
     *
     * @private
     * @param {Element} element
     * @returns {boolean}
     */
    isInteractive(element) {
        const interactiveTags = [
            'A',
            'BUTTON',
            'INPUT',
            'SELECT',
            'TEXTAREA',
            'LABEL',
        ];

        if (interactiveTags.includes(element.tagName)) {
            return true;
        }

        // Check for click handlers (heuristic)
        if (element.onclick || element.hasAttribute('onclick')) {
            return true;
        }

        // Check for cursor pointer
        const style = window.getComputedStyle(element);
        if (style.cursor === 'pointer') {
            return true;
        }

        return false;
    }

    /**
     * Check if element is a text container (p, span, h1-h6, etc.).
     *
     * @private
     * @param {Element} element
     * @returns {boolean}
     */
    isTextContainer(element) {
        const textTags = [
            'P',
            'SPAN',
            'H1',
            'H2',
            'H3',
            'H4',
            'H5',
            'H6',
            'LI',
            'LABEL',
            'TD',
            'TH',
            'CODE',
            'PRE',
        ];

        return textTags.includes(element.tagName);
    }

    /**
     * Get statistics about the last serialization.
     *
     * @returns {Object} Statistics
     */
    getStats() {
        return { ...this.stats };
    }

    /**
     * Calculate approximate payload size (for debugging).
     *
     * @param {Object} serialized - Serialized DOM structure
     * @returns {number} Approximate size in bytes
     */
    estimateSize(serialized) {
        try {
            const json = JSON.stringify(serialized);
            return json.length;
        } catch {
            return 0;
        }
    }

    /**
     * Compress serialized structure (remove nulls, optimize).
     *
     * @param {Object} serialized - Serialized DOM structure
     * @returns {Object} Compressed structure
     */
    compress(serialized) {
        // Remove null/undefined values
        const removeNulls = (obj) => {
            if (Array.isArray(obj)) {
                return obj.map(removeNulls);
            }

            if (obj !== null && typeof obj === 'object') {
                const cleaned = {};
                for (const key in obj) {
                    if (obj[key] !== null && obj[key] !== undefined) {
                        cleaned[key] = removeNulls(obj[key]);
                    }
                }
                return cleaned;
            }

            return obj;
        };

        return removeNulls(serialized);
    }
}

/**
 * Throttled DOM Serializer - Prevents excessive snapshots.
 *
 * Wraps DOMSerializer with throttling to limit snapshot frequency.
 * This prevents performance issues and excessive data collection.
 */
export class ThrottledDOMSerializer {
    constructor(options = {}) {
        this.serializer = new DOMSerializer(options);
        this.throttleMs = options.throttleMs || 1000; // Default: 1 snapshot per second
        this.lastCaptureTime = 0;
        this.pendingCapture = null;
    }

    /**
     * Serialize DOM with throttling.
     *
     * @param {Element} [rootElement] - Root element to serialize
     * @returns {Object|null} Serialized structure or null if throttled
     */
    serialize(rootElement) {
        const now = Date.now();
        const timeSinceLastCapture = now - this.lastCaptureTime;

        // Check if throttled
        if (timeSinceLastCapture < this.throttleMs) {
            // Throttled - schedule capture for later
            if (!this.pendingCapture) {
                const remainingTime = this.throttleMs - timeSinceLastCapture;
                this.pendingCapture = setTimeout(() => {
                    this.pendingCapture = null;
                    this.lastCaptureTime = Date.now();
                    // Capture will happen on next call
                }, remainingTime);
            }
            return null;
        }

        // Not throttled - perform capture
        this.lastCaptureTime = now;
        return this.serializer.serialize(rootElement);
    }

    /**
     * Get serializer statistics.
     *
     * @returns {Object}
     */
    getStats() {
        return this.serializer.getStats();
    }

    /**
     * Clear pending throttle timer.
     */
    clearThrottle() {
        if (this.pendingCapture) {
            clearTimeout(this.pendingCapture);
            this.pendingCapture = null;
        }
    }
}
