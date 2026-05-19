---
phase: 11
slug: documentation-site
status: draft
nyquist_compliant: true
wave_0_complete: false
created: 2026-04-12
---

# Phase 11 — Validation Strategy

> Per-phase validation contract for docs build verification.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | MkDocs build (static site generator) |
| **Config file** | `mkdocs.yml` |
| **Quick run command** | `mkdocs build --strict` |
| **Full suite command** | `mkdocs build --strict && vendor/bin/phpunit` |
| **Estimated runtime** | ~5 seconds |

---

## Sampling Rate

- **After every task commit:** Run `mkdocs build --strict`
- **After every plan wave:** Run `mkdocs build --strict`
- **Before `/gsd-verify-work`:** Build must be clean (exit 0, no warnings)
- **Max feedback latency:** 5 seconds

---

## Wave 0 Requirements

- [ ] `mkdocs.yml` — site configuration with Material theme
- [ ] `docs/requirements.txt` — pinned dependencies
- [ ] `docs/index.md` — landing page
- [ ] `.github/workflows/docs.yml` — GitHub Pages deployment

*Existing PHP test infrastructure covers all bundle functionality.*

---

## Manual-Only Verifications

| Behavior | Why Manual | Test Instructions |
|----------|------------|-------------------|
| PHP syntax highlighting renders correctly | Pygments rendering is visual | Open local preview, check any PHP code block has colored syntax |
| Navigation tabs work across audiences | Layout/UX check | Click each tab (User Guide, Contributor, Architecture), verify sidebar changes |
| Search returns relevant results | Content indexing check | Search for "TenantContext", verify results point to correct pages |

---

## Validation Sign-Off

- [x] All tasks have automated verify (`mkdocs build --strict`)
- [x] Sampling continuity: build check after every task
- [x] Wave 0 covers all infrastructure requirements
- [x] No watch-mode flags
- [x] Feedback latency < 5s
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
