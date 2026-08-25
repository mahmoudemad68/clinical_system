/// <reference types="vite/client" />

/**
 * Typed environment surface.
 *
 * Declared explicitly so `import.meta.env.VITE_API_BASE_URL` is a known
 * `string | undefined` rather than an untyped index lookup. An untyped read
 * here is how an unset variable becomes the literal string "undefined" in a
 * base URL.
 */
interface ImportMetaEnv {
  readonly VITE_API_BASE_URL?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
