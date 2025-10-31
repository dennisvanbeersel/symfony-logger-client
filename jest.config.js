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
    verbose: true,
};
