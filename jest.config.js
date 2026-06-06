/**
 * Jest configuration for ES modules
 */
export default {
    testEnvironment: 'jsdom',
    transform: {},
    moduleNameMapper: {
        '^(\\.{1,2}/.*)\\.js$': '$1',
    },
    testMatch: [
        '**/tests/**/*.test.js',
    ],
    collectCoverageFrom: [
        'assets/src/**/*.js',
        '!assets/src/**/*.test.js',
        '!assets/dist/**',
    ],
    coverageDirectory: 'coverage',
    coverageReporters: ['text', 'lcov', 'html'],
    coverageThreshold: {
        // Overall SDK coverage gate (CI-enforced)
        global: {
            statements: 80,
            lines: 80,
            functions: 80,
        },
        // transport.js is a critical path (network resilience) - hold it higher
        'assets/src/transport.js': {
            statements: 90,
            lines: 90,
        },
    },
    verbose: true,
};
