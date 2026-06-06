/**
 * Unit tests for PayloadBuilder.
 */

import { jest } from '@jest/globals';
import { PayloadBuilder } from '../src/payload-builder.js';

const makeBuilder = (overrides = {}) => {
    const config = { environment: 'test', release: 'r1', ...overrides.config };
    const breadcrumbs = { get: () => [{ type: 'nav' }] };
    const sessionManager = overrides.sessionManager ?? null;
    const sessionHashProvider = overrides.sessionHashProvider ?? (() => 'a'.repeat(64));
    return new PayloadBuilder(config, breadcrumbs, sessionManager, sessionHashProvider);
};

describe('PayloadBuilder.build', () => {
    test('builds a flat API payload with base context/tags merged', () => {
        const builder = makeBuilder({ sessionManager: { getSessionId: () => 'sess-1' } });
        const payload = builder.build(
            new Error('boom'),
            'error',
            { extra: { a: 1 }, tags: { t: 'x' } },
            { base: 'ctx' },
            { baseTag: 'y' },
        );

        expect(payload.type).toBe('Error');
        expect(payload.message).toBe('boom');
        expect(payload.level).toBe('error');
        expect(payload.environment).toBe('test');
        expect(payload.session_hash).toBe('a'.repeat(64));
        expect(payload.session_id).toBe('sess-1');
        expect(payload.context).toEqual({ base: 'ctx', a: 1 });
        expect(payload.tags).toEqual({ baseTag: 'y', t: 'x' });
    });

    test('omits null values (release null is dropped)', () => {
        const builder = makeBuilder({ config: { release: null } });
        const payload = builder.build(new Error('x'), 'error');
        expect('release' in payload).toBe(false);
    });

    test('returns a minimal payload when building throws', () => {
        const builder = makeBuilder();
        // Force getBrowserInfo to blow up by removing navigator.userAgent getter.
        const spy = jest.spyOn(builder, 'getBrowserInfo').mockImplementation(() => {
            throw new Error('nav fail');
        });
        const payload = builder.build(new Error('x'), 'error');
        expect(payload).toEqual({
            type: 'Error',
            message: 'Failed to build error payload',
            file: 'unknown',
            line: 1,
            stack_trace: [],
            level: 'error',
        });
        spy.mockRestore();
    });
});

describe('PayloadBuilder.truncate', () => {
    test('returns short strings unchanged', () => {
        expect(makeBuilder().truncate('hi', 10)).toBe('hi');
    });

    test('truncates and appends an ellipsis', () => {
        const out = makeBuilder().truncate('abcdefghij', 5);
        expect(out).toBe('ab...');
        expect(out.length).toBe(5);
    });

    test('passes through non-strings', () => {
        expect(makeBuilder().truncate(null, 5)).toBeNull();
        expect(makeBuilder().truncate(42, 5)).toBe(42);
    });
});

describe('PayloadBuilder.extractHttpStatusCode', () => {
    const builder = makeBuilder();

    test('reads error.status', () => {
        expect(builder.extractHttpStatusCode({ status: 503 })).toBe(503);
    });

    test('reads options.httpStatusCode', () => {
        expect(builder.extractHttpStatusCode({}, { httpStatusCode: 404 })).toBe(404);
    });

    test('reads extra.http_status_code', () => {
        expect(builder.extractHttpStatusCode({}, { extra: { http_status_code: 418 } })).toBe(418);
    });

    test('parses the status from the error message', () => {
        expect(builder.extractHttpStatusCode({ message: 'HTTP 500 Server Error' })).toBe(500);
    });

    test('returns null when nothing matches', () => {
        expect(builder.extractHttpStatusCode({ message: 'no code here' })).toBeNull();
    });
});

describe('PayloadBuilder.removeNullValues', () => {
    test('strips null and undefined', () => {
        expect(makeBuilder().removeNullValues({ a: 1, b: null, c: undefined, d: 0 }))
            .toEqual({ a: 1, d: 0 });
    });
});

describe('PayloadBuilder.getBrowserInfo', () => {
    test('returns a non-empty string', () => {
        expect(typeof makeBuilder().getBrowserInfo()).toBe('string');
    });
});
