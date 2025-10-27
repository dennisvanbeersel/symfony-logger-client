import js from '@eslint/js';
import globals from 'globals';

export default [
    js.configs.recommended,
    {
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'module',
            globals: {
                ...globals.browser,
                ...globals.es2021,
            },
        },
        rules: {
            // Possible Errors
            'no-console': ['warn', { allow: ['warn', 'error'] }],
            'no-debugger': 'warn',

            // Best Practices
            'eqeqeq': ['error', 'always'],
            'no-eval': 'error',
            'no-implied-eval': 'error',
            'no-var': 'error',
            'prefer-const': 'error',
            'prefer-arrow-callback': 'error',

            // Stylistic
            'indent': ['error', 4],
            'quotes': ['error', 'single'],
            'semi': ['error', 'always'],
            'comma-dangle': ['error', 'always-multiline'],
            'no-trailing-spaces': 'error',
            'eol-last': ['error', 'always'],

            // ES6+
            'arrow-spacing': 'error',
            'no-duplicate-imports': 'error',
            'template-curly-spacing': ['error', 'never'],
        },
    },
    {
        ignores: [
            'vendor/*',
            'node_modules/*',
            'assets/dist/*',
        ],
    },
];
