/**
 * Canonical JavaScript scrub-field list (single source of truth).
 *
 * KEEP IN SYNC with the PHP default in
 * src/DependencyInjection/Configuration.php (`scrub_fields`). The two lists
 * cannot share a runtime value (different languages), so any field added on one
 * side MUST be mirrored on the other. This JS list is intentionally a SUPERSET
 * that also covers common client-side aliases (passwd/pwd/auth/*_token/...).
 *
 * Both the SDK default config (index.js) and the transport scrubber
 * (transport.js) import this list so they never drift.
 *
 * Matching is case-insensitive substring match on the object KEY name.
 *
 * @type {string[]}
 */
export const DEFAULT_SCRUB_FIELDS = Object.freeze([
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
    'credit_card',
    'creditcard',
    'card_number',
    'cvv',
    'ssn',
    'iban',
]);

/**
 * Redact any "user:pass@host" / "user@host" userinfo (credentials) in a URL's
 * authority with "[REDACTED]@host". The authority that follows "scheme://" OR a
 * scheme-relative leading "//" is considered, so an "@" appearing later in the
 * path/query/fragment is left untouched. Returns the URL unchanged when no
 * userinfo is present. Mirrors PHP DataScrubber::scrubUrlUserinfo().
 *
 * JS-SCRUB-01: scheme-relative URLs ("//user:pass@host/path") are now handled
 * too — the value heuristic in transport.js routes them here, so the redactor
 * must understand them or the credential would pass through unchanged.
 *
 * @param {string} url - URL string
 * @returns {string} URL with embedded credentials redacted
 */
export function scrubUrlUserinfo(url) {
    if (typeof url !== 'string') {
        return url;
    }

    let authorityStart;
    const schemePos = url.indexOf('://');
    if (schemePos !== -1) {
        authorityStart = schemePos + '://'.length;
    } else if (url.startsWith('//')) {
        // Scheme-relative URL: the authority follows the leading "//".
        authorityStart = '//'.length;
    } else {
        return url;
    }

    // The authority ends at the first '/', '?' or '#' after "scheme://".
    let authorityEnd = url.length;
    for (const delimiter of ['/', '?', '#']) {
        const pos = url.indexOf(delimiter, authorityStart);
        if (pos !== -1 && pos < authorityEnd) {
            authorityEnd = pos;
        }
    }

    const authority = url.slice(authorityStart, authorityEnd);
    const atPos = authority.lastIndexOf('@');
    if (atPos === -1) {
        return url;
    }

    const hostPart = authority.slice(atPos + 1);

    return `${url.slice(0, authorityStart)}[REDACTED]@${hostPart}${url.slice(authorityEnd)}`;
}

/**
 * Scrub sensitive query-string VALUES from a URL/URI string, and ALWAYS redact
 * any embedded userinfo (credentials) in the authority component.
 *
 * Userinfo ("user:pass@host") is redacted FIRST, then sensitive query VALUES.
 * Otherwise only the query component is touched: scheme/host/path/fragment are
 * left intact. A query pair is redacted when its NAME matches a scrub pattern
 * (case-insensitive substring). Mirrors PHP DataScrubber::scrubUrl().
 * Never throws; returns the input (userinfo-stripped) when there is no query
 * component, and returns '[REDACTED]' if parsing fails (fail safe — never echo
 * back a URL that may carry a sensitive value).
 *
 * Single source of truth shared by transport.js (payload scrubbing) and
 * breadcrumbs.js (fetch/console breadcrumb composition) so the two can never
 * drift.
 *
 * @param {string} value - URL or path+query string
 * @param {string[]} [scrubPatterns=DEFAULT_SCRUB_FIELDS] - Field-name fragments to redact
 * @returns {string} URL with embedded credentials and sensitive query values redacted
 */
export function scrubUrlQueryValues(value, scrubPatterns = DEFAULT_SCRUB_FIELDS) {
    if (typeof value !== 'string') {
        return value;
    }

    try {
        // Redact embedded credentials (userinfo) FIRST so the rest of the
        // function operates on (and returns) a credential-free URL. Mirrors
        // PHP DataScrubber::scrubUrl() ordering.
        value = scrubUrlUserinfo(value);

        const hashIndex = value.indexOf('#');
        const fragment = hashIndex !== -1 ? value.slice(hashIndex) : '';
        const beforeFragment = hashIndex !== -1 ? value.slice(0, hashIndex) : value;

        const qIndex = beforeFragment.indexOf('?');
        if (qIndex === -1) {
            return value; // No query component; nothing to scrub.
        }

        const base = beforeFragment.slice(0, qIndex + 1);
        const query = beforeFragment.slice(qIndex + 1);
        if (query === '') {
            return value;
        }

        const isSensitive = (name) =>
            scrubPatterns.some(pattern => name.toLowerCase().includes(pattern.toLowerCase()));

        const scrubbedPairs = query.split('&').map((pair) => {
            if (pair === '') {
                return pair;
            }
            const eqIndex = pair.indexOf('=');
            let rawName;
            try {
                rawName = decodeURIComponent(eqIndex === -1 ? pair : pair.slice(0, eqIndex));
            } catch {
                rawName = eqIndex === -1 ? pair : pair.slice(0, eqIndex);
            }
            if (!isSensitive(rawName)) {
                return pair;
            }
            const namePart = eqIndex === -1 ? pair : pair.slice(0, eqIndex);
            return `${namePart}=[REDACTED]`;
        });

        return `${base}${scrubbedPairs.join('&')}${fragment}`;
    } catch {
        // Fail safe: never echo back a URL that may carry a sensitive value.
        return '[REDACTED]';
    }
}
