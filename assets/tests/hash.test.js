/**
 * Unit tests for the shared hash utilities.
 */

import { hashString, hashHex64, sha256Hex } from '../src/util/hash.js';

describe('hashString', () => {
    test('is deterministic for the same input', () => {
        expect(hashString('abc')).toBe(hashString('abc'));
    });

    test('differs for different inputs', () => {
        expect(hashString('abc')).not.toBe(hashString('abd'));
    });

    test('returns a string', () => {
        expect(typeof hashString('anything')).toBe('string');
    });
});

describe('hashHex64', () => {
    test('returns a 64-char lowercase hex string', () => {
        expect(hashHex64('hello')).toMatch(/^[0-9a-f]{64}$/);
    });

    test('is deterministic', () => {
        expect(hashHex64('session-1')).toBe(hashHex64('session-1'));
    });

    test('handles empty string', () => {
        expect(hashHex64('')).toMatch(/^[0-9a-f]{64}$/);
    });
});

describe('sha256Hex', () => {
    const hasSubtle = typeof crypto !== 'undefined' && !!crypto.subtle;

    test('returns a 64-char hex digest when SubtleCrypto is available', async () => {
        if (!hasSubtle) {
            // Environment without SubtleCrypto: contract is to return null.
            expect(await sha256Hex('payload')).toBeNull();
            return;
        }
        const digest = await sha256Hex('payload');
        expect(digest).toMatch(/^[0-9a-f]{64}$/);
        // Deterministic for the same input.
        expect(await sha256Hex('payload')).toBe(digest);
    });

    test('returns null when SubtleCrypto is unavailable', async () => {
        const descriptor = Object.getOwnPropertyDescriptor(globalThis, 'crypto');
        Object.defineProperty(globalThis, 'crypto', { value: {}, configurable: true });
        try {
            expect(await sha256Hex('payload')).toBeNull();
        } finally {
            if (descriptor) {
                Object.defineProperty(globalThis, 'crypto', descriptor);
            }
        }
    });
});
