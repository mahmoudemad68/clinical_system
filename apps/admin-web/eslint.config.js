import js from '@eslint/js';
import globals from 'globals';
import tseslint from 'typescript-eslint';
import reactHooks from 'eslint-plugin-react-hooks';
import reactRefresh from 'eslint-plugin-react-refresh';

/**
 * ESLint for the admin application.
 *
 * Type-aware rules are enabled (phase file, "React baseline"). They are slower
 * than syntactic rules and worth it: the defects they catch — a floating
 * promise, an unchecked `any` crossing a boundary — are exactly the ones that
 * reach production because they look fine.
 */
export default tseslint.config(
  {
    ignores: ['dist', 'src/api/generated', 'coverage', 'playwright-report'],
  },

  js.configs.recommended,
  ...tseslint.configs.strictTypeChecked,
  ...tseslint.configs.stylisticTypeChecked,

  {
    files: ['**/*.{ts,tsx}'],
    languageOptions: {
      ecmaVersion: 2022,
      globals: globals.browser,
      parserOptions: {
        projectService: true,
        tsconfigRootDir: import.meta.dirname,
      },
    },
    plugins: {
      'react-hooks': reactHooks,
      'react-refresh': reactRefresh,
    },
    rules: {
      ...reactHooks.configs.recommended.rules,
      'react-refresh/only-export-components': ['warn', { allowConstantExport: true }],

      // A floating promise is a silently swallowed failure. Requiring `void`
      // makes "I am deliberately not awaiting this" visible at the call site.
      '@typescript-eslint/no-floating-promises': 'error',
      '@typescript-eslint/no-misused-promises': 'error',

      // Authorization in the UI affects discoverability only; the server is
      // authoritative. An `any` crossing the API boundary is how a client
      // starts trusting a shape the server never promised.
      '@typescript-eslint/no-explicit-any': 'error',
      '@typescript-eslint/no-unsafe-assignment': 'error',
      '@typescript-eslint/no-unsafe-member-access': 'error',

      '@typescript-eslint/consistent-type-imports': [
        'error',
        { prefer: 'type-imports', fixStyle: 'inline-type-imports' },
      ],

      // Feature code must not reach for fetch directly: one transport wrapper
      // owns credentials, CSRF, request ids, and error mapping.
      'no-restricted-globals': [
        'error',
        { name: 'fetch', message: 'Use apiClient from @/api/client so credentials and CSRF are applied.' },
      ],
    },
  },

  {
    // Plain JS files (this config, and any tooling script) are not in the
    // TypeScript project, so type-aware rules cannot run on them and error out
    // if asked to. Turn them off here rather than widening the tsconfig.
    files: ['**/*.js', '**/*.mjs', '**/*.cjs'],
    extends: [tseslint.configs.disableTypeChecked],
    languageOptions: {
      globals: globals.node,
    },
  },

  {
    // Tests stub globals and assert on loose shapes by nature.
    files: ['**/*.test.{ts,tsx}', 'src/test/**'],
    rules: {
      '@typescript-eslint/no-unsafe-assignment': 'off',
      '@typescript-eslint/no-unsafe-member-access': 'off',
      'no-restricted-globals': 'off',
    },
  },
);
