/**
 * Unit tests for DOMSerializer and ThrottledDOMSerializer.
 *
 * Runs against the real jsdom environment. jsdom's getBoundingClientRect
 * returns zero-sized rects, so tests that need real bounds stub the rect.
 */
import { DOMSerializer, ThrottledDOMSerializer } from '../src/dom-serializer.js';

/**
 * Helper: stub getBoundingClientRect on an element to report a real size.
 */
function withRect(el, rect) {
    el.getBoundingClientRect = () => ({
        left: rect.x ?? 0,
        top: rect.y ?? 0,
        right: (rect.x ?? 0) + (rect.width ?? 0),
        bottom: (rect.y ?? 0) + (rect.height ?? 0),
        width: rect.width ?? 0,
        height: rect.height ?? 0,
    });
    return el;
}

describe('DOMSerializer', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    describe('serialize()', () => {
        test('serializes a simple visible element tree', () => {
            const root = withRect(document.createElement('div'), { x: 0, y: 0, width: 200, height: 100 });
            const child = withRect(document.createElement('button'), { x: 10, y: 10, width: 50, height: 20 });
            root.appendChild(child);
            document.body.appendChild(root);

            const serializer = new DOMSerializer({ minSize: 0 });
            const result = serializer.serialize(root);

            expect(result).not.toBeNull();
            expect(result.tree.type).toBe('div');
            expect(result.tree.bounds).toEqual({ x: 0, y: 0, width: 200, height: 100 });
            expect(result.tree.children).toHaveLength(1);
            expect(result.tree.children[0].type).toBe('button');
            expect(result.tree.children[0].isInteractive).toBe(true);
            expect(result.viewport).toBeDefined();
            expect(result.stats.totalElements).toBeGreaterThanOrEqual(2);
        });

        test('returns null and logs when serialization throws', () => {
            const serializer = new DOMSerializer({ minSize: 0 });
            // Force an error inside serializeElement by passing a bad root
            const result = serializer.serialize(null);
            expect(result).toBeNull();
        });

        test('skips tiny elements', () => {
            const root = withRect(document.createElement('div'), { x: 0, y: 0, width: 2, height: 2 });
            document.body.appendChild(root);

            const serializer = new DOMSerializer({ minSize: 5 });
            const result = serializer.serialize(root);

            expect(result.tree).toBeNull();
            expect(result.stats.skippedTiny).toBe(1);
        });

        test('respects maxDepth', () => {
            const root = withRect(document.createElement('div'), { x: 0, y: 0, width: 100, height: 100 });
            const child = withRect(document.createElement('div'), { x: 0, y: 0, width: 100, height: 100 });
            root.appendChild(child);
            document.body.appendChild(root);

            const serializer = new DOMSerializer({ minSize: 0, maxDepth: 1 });
            const result = serializer.serialize(root);

            // Only the root captured; child exceeds maxDepth
            expect(result.tree.children).toBeUndefined();
            expect(result.stats.maxDepthReached).toBe(1);
        });
    });

    describe('isNonVisualElement()', () => {
        test('treats script/style/meta as non-visual', () => {
            const serializer = new DOMSerializer();
            expect(serializer.isNonVisualElement(document.createElement('script'))).toBe(true);
            expect(serializer.isNonVisualElement(document.createElement('style'))).toBe(true);
            expect(serializer.isNonVisualElement(document.createElement('div'))).toBe(false);
        });

        test('serialize skips non-visual children', () => {
            const root = withRect(document.createElement('div'), { x: 0, y: 0, width: 100, height: 100 });
            root.appendChild(document.createElement('script'));
            document.body.appendChild(root);

            const serializer = new DOMSerializer({ minSize: 0 });
            const result = serializer.serialize(root);

            expect(result.tree.children).toBeUndefined();
            expect(result.stats.skippedNonVisual).toBe(1);
        });
    });

    describe('isInvisible()', () => {
        test('detects display:none', () => {
            const serializer = new DOMSerializer();
            const el = document.createElement('div');
            expect(serializer.isInvisible(el, { display: 'none', visibility: 'visible', opacity: '1' })).toBe(true);
        });

        test('detects visibility:hidden', () => {
            const serializer = new DOMSerializer();
            const el = withRect(document.createElement('div'), { width: 10, height: 10 });
            expect(serializer.isInvisible(el, { display: 'block', visibility: 'hidden', opacity: '1' })).toBe(true);
        });

        test('detects opacity 0', () => {
            const serializer = new DOMSerializer();
            const el = withRect(document.createElement('div'), { width: 10, height: 10 });
            expect(serializer.isInvisible(el, { display: 'block', visibility: 'visible', opacity: '0' })).toBe(true);
        });

        test('detects off-screen elements', () => {
            const serializer = new DOMSerializer();
            const el = withRect(document.createElement('div'), { x: -5000, y: 0, width: 10, height: 10 });
            expect(serializer.isInvisible(el, { display: 'block', visibility: 'visible', opacity: '1' })).toBe(true);
        });

        test('returns false for a visible element', () => {
            const serializer = new DOMSerializer();
            const el = withRect(document.createElement('div'), { x: 0, y: 0, width: 10, height: 10 });
            expect(serializer.isInvisible(el, { display: 'block', visibility: 'visible', opacity: '1' })).toBe(false);
        });
    });

    describe('color helpers', () => {
        test('extractBackgroundColor returns null for transparent', () => {
            const serializer = new DOMSerializer();
            expect(serializer.extractBackgroundColor({ backgroundColor: 'rgba(0, 0, 0, 0)' })).toBeNull();
            expect(serializer.extractBackgroundColor({ backgroundColor: 'transparent' })).toBeNull();
            expect(serializer.extractBackgroundColor({ backgroundColor: '' })).toBeNull();
        });

        test('extractBackgroundColor converts rgb to hex', () => {
            const serializer = new DOMSerializer();
            expect(serializer.extractBackgroundColor({ backgroundColor: 'rgb(255, 0, 16)' })).toBe('#ff0010');
        });

        test('rgbToHex handles rgba and returns null on no match', () => {
            const serializer = new DOMSerializer();
            expect(serializer.rgbToHex('rgba(16, 32, 48, 0.5)')).toBe('#102030');
            expect(serializer.rgbToHex('not-a-color')).toBeNull();
        });
    });

    describe('layout / classification helpers', () => {
        test('detectLayoutType maps display values', () => {
            const serializer = new DOMSerializer();
            expect(serializer.detectLayoutType({ display: 'flex' })).toBe('flex');
            expect(serializer.detectLayoutType({ display: 'inline-grid' })).toBe('grid');
            expect(serializer.detectLayoutType({ display: 'inline-block' })).toBe('inline');
            expect(serializer.detectLayoutType({ display: 'block' })).toBe('block');
        });

        test('isInteractive detects interactive tags and pointer cursor', () => {
            const serializer = new DOMSerializer();
            expect(serializer.isInteractive(document.createElement('a'))).toBe(true);
            expect(serializer.isInteractive(document.createElement('input'))).toBe(true);

            const div = document.createElement('div');
            div.setAttribute('onclick', 'doThing()');
            expect(serializer.isInteractive(div)).toBe(true);
        });

        test('isTextContainer detects text tags', () => {
            const serializer = new DOMSerializer();
            expect(serializer.isTextContainer(document.createElement('p'))).toBe(true);
            expect(serializer.isTextContainer(document.createElement('h2'))).toBe(true);
            expect(serializer.isTextContainer(document.createElement('div'))).toBe(false);
        });
    });

    describe('estimateSize / getStats', () => {
        test('estimateSize returns json length', () => {
            const serializer = new DOMSerializer();
            const size = serializer.estimateSize({ a: 1, b: 'two' });
            expect(size).toBeGreaterThan(0);
        });

        test('estimateSize returns 0 on circular structure', () => {
            const serializer = new DOMSerializer();
            const obj = {};
            obj.self = obj;
            expect(serializer.estimateSize(obj)).toBe(0);
        });

        test('getStats returns a copy of stats', () => {
            const serializer = new DOMSerializer();
            const stats = serializer.getStats();
            expect(stats).toHaveProperty('totalElements');
        });
    });
});

describe('ThrottledDOMSerializer', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    test('first call serializes, immediate second call is throttled (null)', () => {
        const root = withRect(document.createElement('div'), { x: 0, y: 0, width: 100, height: 100 });
        document.body.appendChild(root);

        const throttled = new ThrottledDOMSerializer({ minSize: 0, throttleMs: 10000 });

        const first = throttled.serialize(root);
        expect(first).not.toBeNull();

        const second = throttled.serialize(root);
        expect(second).toBeNull();

        throttled.clearThrottle();
    });

    test('clearThrottle reopens the gate so the next serialize captures', () => {
        const root = withRect(document.createElement('div'), { x: 0, y: 0, width: 100, height: 100 });
        document.body.appendChild(root);

        const throttled = new ThrottledDOMSerializer({ minSize: 0, throttleMs: 10000 });
        expect(throttled.serialize(root)).not.toBeNull(); // first capture
        expect(throttled.serialize(root)).toBeNull(); // gated within window

        throttled.clearThrottle();
        expect(throttled.serialize(root)).not.toBeNull(); // gate reopened
    });

    test('getStats delegates to the underlying serializer', () => {
        const throttled = new ThrottledDOMSerializer({ minSize: 0 });
        expect(throttled.getStats()).toHaveProperty('totalElements');
    });
});
