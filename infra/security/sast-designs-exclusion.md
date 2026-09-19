# SAST exclusion for `designs/`

This file owns the CI rationale for excluding `designs/**` from the
pull-request Semgrep job. Do not document that exclusion inside `designs/`.

## What is excluded

`.github/workflows/pull-request.yaml` runs digest-pinned
`semgrep/semgrep:1.99.0` with `--exclude 'designs/**'`.

`designs/*/code.html` files are local Stitch mockups. They are not a served
runtime. They load the Tailwind Play compiler from a CDN, and that compiler URL
cannot carry a stable subresource integrity hash. Semgrep `missing-integrity`
therefore fails closed on every mockup HTML file (117 hits on `main`'s tree).

## What is not relaxed

- `--error` remains.
- The four rulesets remain: `p/default`, `p/security-audit`, `p/secrets`,
  `p/owasp-top-ten`.
- Production Inertia, Electron, and Flutter surfaces under `apps/` and
  `packages/` remain fully scanned.
- Do not copy Tailwind Play CDN tags into shipped clients.

## Invalidation

Remove the exclude if Stitch mockups are deleted, moved out of the repository,
or rewritten so they do not load an unauditable CDN compiler.
