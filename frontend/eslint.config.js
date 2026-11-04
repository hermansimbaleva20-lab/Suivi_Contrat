import js from '@eslint/js';
import globals from 'globals';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import reactRefresh from 'eslint-plugin-react-refresh';

export default [
  { ignores: ['dist/**', 'node_modules/**'] },
  {
    files: ['**/*.{js,jsx}'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: globals.browser,
      parserOptions: { ecmaFeatures: { jsx: true } },
    },
    settings: { react: { version: '18.3' } },
    plugins: { react, 'react-hooks': reactHooks, 'react-refresh': reactRefresh },
    rules: {
      ...js.configs.recommended.rules,
      ...react.configs.recommended.rules,
      ...react.configs['jsx-runtime'].rules,
      ...reactHooks.configs.recommended.rules,
      'react/prop-types': 'off',
      'react/no-unescaped-entities': 'off',
      'no-unused-vars': ['warn', { argsIgnorePattern: '^_', varsIgnorePattern: '^(_|React$)', caughtErrors: 'none' }],
      'no-empty': ['error', { allowEmptyCatch: true }],
      'react-refresh/only-export-components': ['warn', { allowConstantExport: true }],
      // Sécurité : pas de HTML injecté ni de code évalué dynamiquement.
      'react/no-danger': 'error',
      'react/jsx-no-target-blank': 'error',
      'no-eval': 'error',
      'no-implied-eval': 'error',
      'no-new-func': 'error',
    },
  },
  {
    files: ['vite.config.js', 'eslint.config.js'],
    languageOptions: { globals: globals.node },
  },
];
