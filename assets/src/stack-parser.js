/**
 * Cross-browser stack trace parser.
 *
 * Pure functions extracted from client.js. Converts an Error's stack string
 * into an array of frames matching the API format:
 * [{file, line, function, column}, ...]
 *
 * Supports Chrome/V8, Firefox, Safari and legacy Edge stack formats.
 */

// API requires line > 0 (Positive constraint), so unknown frames default to 1.
const UNKNOWN_FRAME = Object.freeze({
    file: 'unknown',
    line: 1,
    function: 'unknown',
});

/**
 * Parse a single stack trace line across browser formats.
 *
 * @param {string} line - A trimmed stack trace line
 * @returns {{function: string, file: string, line: number, column: (number|null)}|null}
 *          Parsed frame, or null when the line could not be parsed
 */
export function parseStackLine(line) {
    if (!line) {
        return null;
    }

    // Chrome/V8: "at functionName (file.js:line:col)"
    let match = line.match(/at\s+(.+?)\s+\((.+?):(\d+):(\d+)\)/);
    if (match) {
        return {
            function: match[1].trim(),
            file: match[2],
            line: parseInt(match[3], 10),
            column: parseInt(match[4], 10),
        };
    }

    // Chrome/V8 anonymous: "at file.js:line:col"
    match = line.match(/at\s+(.+?):(\d+):(\d+)/);
    if (match) {
        return {
            function: 'anonymous',
            file: match[1],
            line: parseInt(match[2], 10),
            column: parseInt(match[3], 10),
        };
    }

    // Firefox: "functionName@file.js:line:col"
    match = line.match(/(.+?)@(.+?):(\d+):(\d+)/);
    if (match) {
        return {
            function: match[1] || 'anonymous',
            file: match[2],
            line: parseInt(match[3], 10),
            column: parseInt(match[4], 10),
        };
    }

    // Safari/Firefox (no column): "functionName@file.js:line"
    match = line.match(/(?:(.+)@)?(.+?):(\d+)$/);
    if (match) {
        return {
            function: match[1] || 'anonymous',
            file: match[2],
            line: parseInt(match[3], 10),
            column: null,
        };
    }

    // Edge legacy: "at functionName [file.js:line:col]"
    match = line.match(/at\s+(.+?)\s+\[(.+?):(\d+):(\d+)\]/);
    if (match) {
        return {
            function: match[1].trim(),
            file: match[2],
            line: parseInt(match[3], 10),
            column: parseInt(match[4], 10),
        };
    }

    return null;
}

/**
 * Parse an Error's stack trace into an array of frames.
 *
 * Always returns at least one frame; falls back to a single "unknown" frame
 * when the stack is missing or unparseable.
 *
 * @param {Error} error - Error whose stack should be parsed
 * @returns {Array<Object>} Array of stack frames
 */
export function parseStackTrace(error) {
    if (!error || !error.stack) {
        return [{ ...UNKNOWN_FRAME }];
    }

    try {
        const frames = [];
        for (const line of error.stack.split('\n')) {
            const frame = parseStackLine(line.trim());
            if (frame) {
                frames.push(frame);
            }
        }

        return frames.length > 0 ? frames : [{ ...UNKNOWN_FRAME }];
    } catch {
        return [{ ...UNKNOWN_FRAME }];
    }
}
