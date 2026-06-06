/**
 * Unit tests for the cross-browser stack parser.
 */

import { parseStackTrace, parseStackLine } from '../src/stack-parser.js';

describe('parseStackLine', () => {
    test('returns null for empty input', () => {
        expect(parseStackLine('')).toBeNull();
        expect(parseStackLine(null)).toBeNull();
    });

    test('parses Chrome/V8 named frame', () => {
        const frame = parseStackLine('at doThing (https://app.test/a.js:12:5)');
        expect(frame).toEqual({ function: 'doThing', file: 'https://app.test/a.js', line: 12, column: 5 });
    });

    test('parses Chrome/V8 anonymous frame', () => {
        const frame = parseStackLine('at https://app.test/a.js:3:9');
        expect(frame).toEqual({ function: 'anonymous', file: 'https://app.test/a.js', line: 3, column: 9 });
    });

    test('parses Firefox frame', () => {
        const frame = parseStackLine('doThing@https://app.test/a.js:7:2');
        expect(frame.function).toBe('doThing');
        expect(frame.file).toBe('https://app.test/a.js');
        expect(frame.line).toBe(7);
    });

    test('returns null for an unparseable line', () => {
        expect(parseStackLine('totally not a stack frame')).toBeNull();
    });
});

describe('parseStackTrace', () => {
    test('returns an unknown frame when there is no stack', () => {
        const frames = parseStackTrace({ });
        expect(frames).toEqual([{ file: 'unknown', line: 1, function: 'unknown' }]);
    });

    test('returns an unknown frame for null error', () => {
        expect(parseStackTrace(null)).toEqual([{ file: 'unknown', line: 1, function: 'unknown' }]);
    });

    test('parses a multi-line Chrome stack', () => {
        const error = new Error('boom');
        error.stack = [
            'Error: boom',
            '    at first (https://app.test/a.js:1:1)',
            '    at second (https://app.test/b.js:2:2)',
        ].join('\n');

        const frames = parseStackTrace(error);
        expect(frames.length).toBe(2);
        expect(frames[0].function).toBe('first');
        expect(frames[1].file).toBe('https://app.test/b.js');
    });

    test('falls back to an unknown frame when no lines parse', () => {
        const error = new Error('boom');
        error.stack = 'garbage\nmore garbage';
        expect(parseStackTrace(error)).toEqual([{ file: 'unknown', line: 1, function: 'unknown' }]);
    });
});
