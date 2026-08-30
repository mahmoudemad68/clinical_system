import { defineConfig } from 'vitest/config';

export default defineConfig({
  define: {
    __CLINIC_PACKAGED_API_ALLOWED_ORIGINS__: JSON.stringify([]),
  },
  test: {
    globals: true,

    /**
     * Node, not jsdom, by default.
     *
     * The trust-boundary suite inspects source and schemas; it needs no DOM.
     * jsdom also refuses to run under the packaged asset scheme —
     * `localStorage is not available for opaque origins` — because it does not
     * know the scheme was registered as standard and privileged, which
     * Electron does at runtime.
     *
     * Renderer component tests, when they arrive, opt in per file with
     * `// @vitest-environment jsdom` and a plain http origin.
     */
    environment: 'node',
    include: ['src/**/*.test.ts', 'src/**/*.test.tsx'],
  },
});
