/**
 * Shared hashing utilities.
 *
 * Single source of truth for the SDK's hash functions. Previously the same
 * djb2/32-bit logic was duplicated across Transport (simpleHash), Client
 * (djb2Hash) and informally in ErrorDetector. Consolidated here so every call
 * site shares one implementation.
 */

/**
 * djb2 string hash, returned as a decimal string.
 *
 * Cheap, deterministic, collision-resistant enough for in-memory dedup keys.
 * NOT cryptographic. Used by Transport deduplication.
 *
 * @param {string} str - Input string
 * @returns {string} Decimal representation of a 32-bit hash
 */
export function hashString(str) {
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
        hash = ((hash << 5) - hash) + str.charCodeAt(i);
        hash = hash & hash; // Force to 32-bit integer
    }
    return hash.toString();
}

/**
 * djb2 hash rendered as a 64-character lowercase hex string.
 *
 * Used as the non-cryptographic fallback for session_hash on browsers without
 * the Web Crypto API. The 64-hex shape matches the API's session_hash regex
 * (the same shape a real SHA-256 produces), so the backend accepts it.
 *
 * @param {string} str - Input string
 * @returns {string} 64-character hexadecimal hash
 */
export function hashHex64(str) {
    let hash = 5381;
    for (let i = 0; i < str.length; i++) {
        hash = ((hash << 5) + hash) + str.charCodeAt(i);
    }
    return Math.abs(hash).toString(16).padStart(64, '0');
}

/**
 * Compute a real SHA-256 hex digest via the Web Crypto API.
 *
 * Returns null when SubtleCrypto is unavailable (callers fall back to
 * {@link hashHex64}).
 *
 * @param {string} str - Input string
 * @returns {Promise<string|null>} 64-character hex digest, or null if unsupported
 */
export async function sha256Hex(str) {
    if (typeof crypto === 'undefined' || !crypto.subtle) {
        return null;
    }
    const data = new TextEncoder().encode(str);
    const buffer = await crypto.subtle.digest('SHA-256', data);
    return Array.from(new Uint8Array(buffer))
        .map(b => b.toString(16).padStart(2, '0'))
        .join('');
}
