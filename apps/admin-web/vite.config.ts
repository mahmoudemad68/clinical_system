// defineConfig from vitest/config, not vite: the `test` key is a Vitest
// extension and vite's own type does not know about it.
import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    // Bind loopback only. A dev server on 0.0.0.0 exposes an unauthenticated
    // build of the admin application to the local network.
    host: '127.0.0.1',
    proxy: {
      // The admin authenticates with a session cookie, so it must be
      // same-origin in development or the cookie will not be sent.
      '/api': { target: 'http://localhost:8080', changeOrigin: false },
    },
  },
  build: {
    // Fail the build rather than shipping an unreviewed source map to
    // production; source maps expose the full application source.
    sourcemap: false,
    target: 'es2022',
  },
  test: {
    globals: true,
    environment: 'jsdom',
    environmentOptions: {
      // Give jsdom a real origin so relative API paths resolve, matching how
      // the application actually runs in a browser.
      jsdom: { url: 'http://localhost:5173' },
    },
    setupFiles: ['./src/test/setup.ts'],
    css: false,
  },
});
