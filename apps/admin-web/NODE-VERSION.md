# Node version

**Node 22 LTS (`>=22.12.0`), enforced by `engines` in the root `package.json`.**

## Why not Node 20

The toolchain requires it. `vitest@4` depends on `jsdom@30`, whose engines range
is `^22.22.2 || ^24.15.0 || >=26.0.0`. On Node 20 the test environment fails at
worker start:

```
TypeError: webidl.util.markAsUncloneable is not a function
```

`markAsUncloneable` is a Node 22+ API. Pinning `jsdom` back to 29 as a direct
dependency does not help, because npm resolves `vitest`'s own nested copy and
that is the one the test environment loads. A root `overrides` entry was tried
and did not reliably win either.

Rather than pin a transitive dependency backwards and let the test environment
drift further from what Vite 8, Vitest 4, and jsdom 30 all expect, the project
targets Node 22. Node 20 is approaching end of life regardless.

## If you see the error above

You are on Node 20. Switch to 22:

```bash
nvm use 22
```

CI pins the same major in `.github/workflows/pull-request.yaml` (`NODE_VERSION`).
