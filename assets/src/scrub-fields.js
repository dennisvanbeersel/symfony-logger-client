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
