---
status: partial
phase: 22-docs-refresh
source: [22-VERIFICATION.md]
started: "2026-05-28T17:00:00Z"
updated: "2026-05-28T17:00:00Z"
---

## Current Test

[awaiting human testing]

## Tests

### 1. `mkdocs build --strict` exits 0
expected: A local or CI run of `mkdocs build --strict` produces zero warnings/errors. Verifier could not run this locally because `mkdocs` is not on PATH. Canonical gate is `.github/workflows/docs.yml` line 39 on push to master.
result: [pending — defer to CI on next push, or run locally via `pip install -r docs/requirements.txt && mkdocs build --strict`]

### 2. Profiler-tab ASCII renders look correct in MkDocs Material (light + dark)
expected: The three ASCII panel renders in `docs/user-guide/profiler-tab.md` (resolved / no-tenant / error) align cleanly in the Material theme's monospace font, both light and dark variants. Box-drawing characters do not break alignment.
result: [pending — visual inspection after `mkdocs serve` locally or on the published site]

### 3. `https://danplaton4.github.io/tenancy-bundle/roadmap/` resolves after publish
expected: After the next CI push triggers a GitHub Pages rebuild, the docs-site Roadmap URL resolves and renders the canonical roadmap content from `docs/roadmap.md`.
result: [pending — post-publish only]

### 4. Cross-tree links from docs/ to repo-root `examples/saas/README.md` render acceptably
expected: Links in `docs/examples/saas-demo.md` and `docs/user-guide/profiler-tab.md` that target `../../examples/saas/README.md` resolve to a working file on the docs site OR are presented as an external GitHub link. RESEARCH §Landmines #1 accepted this trade-off, but a real-eye check confirms the rendered link surface is not jarring.
result: [pending — visual inspection on rendered docs]

## Summary

total: 4
passed: 0
issues: 0
pending: 4
skipped: 0
blocked: 0

## Gaps
