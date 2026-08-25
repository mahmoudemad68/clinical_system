# TypeScript version choice

**Pinned: 5.9.3, not the latest 7.0.2.**

ADR 0008 requires versions to be resolved against the registry rather than
copied from a document, and the registry's latest is 7.0.2. This is a
deliberate, recorded exception to taking the newest.

TypeScript 7 is the native-port compiler rewrite. At the time of pinning, the
surrounding toolchain — `@vitejs/plugin-react`, `vitest`, the ESLint TypeScript
integration, and the MUI type surface — has not been verified against it in this
project. Adopting a compiler rewrite and a new application on the same day makes
any type error ambiguous: a genuine bug and a compiler-compatibility gap look
identical, and the first one costs a day to tell apart.

5.9.3 is the current 5.x, which every tool in this stack supports today.

**Revisit when:** the Phase 00 CI pipeline is green and stable, and the toolchain
above publishes explicit TypeScript 7 support. Moving is then a one-line change
with a full suite behind it, which is the cheap moment to do it.

Tracked in the evidence ledger under G-06-04.
