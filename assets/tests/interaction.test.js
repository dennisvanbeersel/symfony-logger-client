/**
 * Unit tests for the shared user-interaction predicate.
 */

import { countUserInteractions, hasUserInteraction } from '../src/util/interaction.js';

describe('countUserInteractions', () => {
    test('counts click events only', () => {
        const events = [
            { type: 'click' },
            { type: 'scroll' },
            { type: 'click' },
            null,
            { type: 'input' },
        ];
        expect(countUserInteractions(events)).toBe(2);
    });

    test('returns 0 for non-arrays', () => {
        expect(countUserInteractions(null)).toBe(0);
        expect(countUserInteractions(undefined)).toBe(0);
        expect(countUserInteractions('nope')).toBe(0);
    });
});

describe('hasUserInteraction', () => {
    test('true when at least one click is present', () => {
        expect(hasUserInteraction([{ type: 'scroll' }, { type: 'click' }])).toBe(true);
    });

    test('false when there are no clicks', () => {
        expect(hasUserInteraction([{ type: 'scroll' }, { type: 'input' }])).toBe(false);
    });

    test('false for empty / invalid input', () => {
        expect(hasUserInteraction([])).toBe(false);
        expect(hasUserInteraction(null)).toBe(false);
    });
});
