/**
 * Unit tests for SessionManager
 *
 * Tests cross-page session continuity, UUID generation, timeout tracking,
 * page transition detection, and localStorage persistence.
 */

import { SessionManager } from '../src/session-manager.js';

// Mock localStorage
const localStorageMock = (() => {
    let store = {};
    return {
        getItem: (key) => store[key] || null,
        setItem: (key, value) => { store[key] = value.toString(); },
        removeItem: (key) => { delete store[key]; },
        clear: () => { store = {}; },
    };
})();

global.localStorage = localStorageMock;

describe('SessionManager', () => {
    let manager;

    beforeEach(() => {
        localStorage.clear();
        manager = new SessionManager({
            sessionTimeoutMinutes: 30,
            debug: false,
        });
    });

    describe('Constructor and initialization', () => {
        test('enforces hard cap on session timeout', () => {
            const overLimit = new SessionManager({
                sessionTimeoutMinutes: 200, // Max 120
            });

            expect(overLimit.config.sessionTimeoutMinutes).toBe(120);
        });

        test('generates new session ID on first initialization', () => {
            expect(manager.sessionId).toBeDefined();
            expect(manager.sessionId).toMatch(/^[a-f0-9-]{36}$/);
        });

        test('initializes metadata correctly', () => {
            expect(manager.metadata.startedAt).toBeDefined();
            expect(manager.metadata.lastActivityAt).toBeDefined();
            expect(manager.metadata.pageCount).toBe(1); // First page tracked in constructor
            expect(manager.metadata.pages).toHaveLength(1);
        });

        test('loads existing session from localStorage', () => {
            const existingSessionId = '550e8400-e29b-41d4-a716-446655440000';
            localStorage.setItem('_app_logger_session_id', existingSessionId);
            localStorage.setItem('_app_logger_session_metadata', JSON.stringify({
                startedAt: Date.now() - 60000,
                lastActivityAt: Date.now(),
                pageCount: 5,
                pages: [],
            }));

            const newManager = new SessionManager({});

            expect(newManager.sessionId).toBe(existingSessionId);
            expect(newManager.metadata.pageCount).toBe(6); // 5 + current page
        });
    });

    describe('Session persistence', () => {
        test('saveSession stores session ID and metadata in localStorage', () => {
            manager.saveSession();

            const savedId = localStorage.getItem('_app_logger_session_id');
            const savedMetadata = localStorage.getItem('_app_logger_session_metadata');

            expect(savedId).toBe(manager.sessionId);
            expect(savedMetadata).toBeDefined();

            const metadata = JSON.parse(savedMetadata);
            expect(metadata.startedAt).toBeDefined();
            expect(metadata.lastActivityAt).toBeDefined();
        });

        test('loadSession retrieves session from localStorage', () => {
            const testSessionId = 'test-session-123';
            const testMetadata = {
                startedAt: Date.now() - 120000,
                lastActivityAt: Date.now(),
                pageCount: 10,
                pages: [{ url: 'https://example.com', timestamp: Date.now() }],
            };

            localStorage.setItem('_app_logger_session_id', testSessionId);
            localStorage.setItem('_app_logger_session_metadata', JSON.stringify(testMetadata));

            const loaded = manager.loadSession();

            expect(loaded).toBe(true);
            expect(manager.sessionId).toBe(testSessionId);
            expect(manager.metadata.pageCount).toBe(10);
        });

        test('loadSession returns false when no data exists', () => {
            localStorage.clear();

            const loaded = manager.loadSession();

            expect(loaded).toBe(false);
        });
    });

    describe('Session expiration', () => {
        test('isSessionExpired returns false for recent activity', () => {
            manager.metadata.lastActivityAt = Date.now();

            expect(manager.isSessionExpired()).toBe(false);
        });

        test('isSessionExpired returns true when timeout exceeded', () => {
            // Set last activity to 35 minutes ago (beyond 30 min timeout)
            manager.metadata.lastActivityAt = Date.now() - (35 * 60 * 1000);

            expect(manager.isSessionExpired()).toBe(true);
        });

        test('creates new session when existing session expired', () => {
            const oldSessionId = manager.sessionId;

            // Simulate expired session
            manager.metadata.lastActivityAt = Date.now() - (40 * 60 * 1000);
            localStorage.setItem('_app_logger_session_id', oldSessionId);
            localStorage.setItem('_app_logger_session_metadata', JSON.stringify(manager.metadata));

            // Create new manager (should detect expiration)
            const newManager = new SessionManager({});

            expect(newManager.sessionId).not.toBe(oldSessionId);
            expect(newManager.metadata.pageCount).toBe(1);
        });
    });

    describe('Page tracking', () => {
        test('trackPageView increments page count', () => {
            const initialCount = manager.metadata.pageCount;

            manager.trackPageView('https://example.com/page2');

            expect(manager.metadata.pageCount).toBe(initialCount + 1);
        });

        test('trackPageView adds to pages array', () => {
            const url = 'https://example.com/page2';
            const initialLength = manager.metadata.pages.length;

            const pageEvent = manager.trackPageView(url);

            expect(manager.metadata.pages.length).toBe(initialLength + 1);
            expect(manager.metadata.pages[manager.metadata.pages.length - 1].url).toBe(url);
            expect(pageEvent.type).toBe('pageTransition');
            expect(pageEvent.url).toBe(url);
            expect(pageEvent.sessionId).toBe(manager.sessionId);
        });

        test('trackPageView limits pages array to 50 entries', () => {
            // Add 100 pages
            for (let i = 0; i < 100; i++) {
                manager.trackPageView(`https://example.com/page${i}`);
            }

            expect(manager.metadata.pages.length).toBeLessThanOrEqual(50);
        });

        test('trackPageView updates last activity timestamp', () => {
            const before = manager.metadata.lastActivityAt;

            // Wait a bit
            setTimeout(() => {
                manager.trackPageView('https://example.com/page2');

                expect(manager.metadata.lastActivityAt).toBeGreaterThan(before);
            }, 10);
        });
    });

    describe('Session ID generation', () => {
        test('generateSessionId creates UUID v4 format', () => {
            const sessionId = manager.generateSessionId();

            // UUID v4 format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
            expect(sessionId).toMatch(/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[a-f0-9]{4}-[a-f0-9]{12}$/);
        });

        test('generateSessionId creates unique IDs', () => {
            const id1 = manager.generateSessionId();
            const id2 = manager.generateSessionId();

            expect(id1).not.toBe(id2);
        });
    });

    describe('Session management', () => {
        test('clearSession removes data and resets state', () => {
            manager.clearSession();

            expect(localStorage.getItem('_app_logger_session_id')).toBeNull();
            expect(localStorage.getItem('_app_logger_session_metadata')).toBeNull();
            expect(manager.sessionId).toBeNull();
            expect(manager.metadata.startedAt).toBeNull();
            expect(manager.metadata.pageCount).toBe(0);
        });

        test('extendSession updates last activity timestamp', () => {
            const before = manager.metadata.lastActivityAt;

            manager.extendSession();

            expect(manager.metadata.lastActivityAt).toBeGreaterThanOrEqual(before);
        });

        test('getSessionId returns current session ID', () => {
            const id = manager.getSessionId();

            expect(id).toBe(manager.sessionId);
            expect(id).toMatch(/^[a-f0-9-]{36}$/);
        });

        test('getMetadata returns copy of metadata', () => {
            const metadata = manager.getMetadata();

            expect(metadata).toEqual(manager.metadata);
            expect(metadata).not.toBe(manager.metadata); // Should be a copy
        });

        test('getSessionAge returns correct duration', () => {
            // Set start time to 5 minutes ago
            manager.metadata.startedAt = Date.now() - (5 * 60 * 1000);

            const age = manager.getSessionAge();

            // Should be approximately 5 minutes (allow 1 second tolerance)
            expect(age).toBeGreaterThan(5 * 60 * 1000 - 1000);
            expect(age).toBeLessThan(5 * 60 * 1000 + 1000);
        });
    });

    describe('Session info', () => {
        test('getSessionInfo returns comprehensive session data', () => {
            manager.metadata.startedAt = Date.now() - (10 * 60 * 1000); // 10 minutes ago

            const info = manager.getSessionInfo();

            expect(info).toHaveProperty('sessionId');
            expect(info).toHaveProperty('age');
            expect(info).toHaveProperty('ageMinutes');
            expect(info).toHaveProperty('isExpired');
            expect(info).toHaveProperty('pageCount');
            expect(info).toHaveProperty('recentPages');
            expect(info).toHaveProperty('timeoutMinutes');

            expect(info.sessionId).toBe(manager.sessionId);
            expect(info.ageMinutes).toBe(10);
            expect(info.isExpired).toBe(false);
            expect(info.timeoutMinutes).toBe(30);
        });
    });

    describe('Page transition tracking', () => {
        test('setupPageTransitionTracking hooks history API', () => {
            const originalPushState = window.history.pushState;
            const originalReplaceState = window.history.replaceState;

            manager.setupPageTransitionTracking();

            expect(window.history.pushState).not.toBe(originalPushState);
            expect(window.history.replaceState).not.toBe(originalReplaceState);
        });

        test('handleNavigationChange tracks page transitions', () => {
            const initialCount = manager.metadata.pageCount;

            // Simulate navigation
            global.window = { location: { href: 'https://example.com/newpage' } };
            manager.handleNavigationChange();

            expect(manager.metadata.pageCount).toBe(initialCount + 1);
        });
    });

    describe('Error handling', () => {
        test('handles corrupt localStorage data gracefully', () => {
            localStorage.setItem('_app_logger_session_metadata', 'invalid JSON{');

            const loaded = manager.loadSession();

            expect(loaded).toBe(false);
        });

        test('handles missing metadata fields gracefully', () => {
            localStorage.setItem('_app_logger_session_id', 'test-id');
            localStorage.setItem('_app_logger_session_metadata', JSON.stringify({}));

            const loaded = manager.loadSession();

            expect(loaded).toBe(false);
        });
    });
});
