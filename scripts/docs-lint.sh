#!/usr/bin/env bash
#
# docs-lint.sh — fail CI when post-v0.2 docs contain stale references.
#
# Stale terms (removed during Phase 15 v0.2 architectural fixes):
#   - wrapperClass       — replaced by doctrine.middleware
#   - wrapper_class      — YAML form of the above
#   - ReflectionProperty — the v0.1 connection-mutation hack
#   - sqlite://          — placeholder URL form that misled users into driver-family confusion
#   - TenantConnection   — deleted class (no external users)
#
# The script scopes its scan to docs/ + the command file that emits the tenancy:init
# sample. CHANGELOG.md / UPGRADE.md are NOT scanned — they intentionally reference the
# deleted class in the migration recipe.
#
# Run from the repo root.

set -euo pipefail

EXIT=0

check() {
    local pattern="$1"
    local desc="$2"
    shift 2
    local targets=("$@")

    if grep -rnE --color=auto -- "$pattern" "${targets[@]}" 2>/dev/null; then
        echo ""
        echo "ERROR: $desc — remove these occurrences or justify via an inline comment."
        EXIT=1
    fi
}

# Scope: all docs, plus the command file that emits the sample YAML.
TARGETS=(docs/ src/Command/TenantInitCommand.php)

check 'wrapperClass' "Found 'wrapperClass' (v0.1 DBAL approach — use doctrine.middleware tag)" "${TARGETS[@]}"
check 'wrapper_class' "Found 'wrapper_class' (v0.1 YAML form — remove from doctrine.yaml samples)" "${TARGETS[@]}"
check 'ReflectionProperty' "Found 'ReflectionProperty' (v0.1 hack — middleware replaces it)" "${TARGETS[@]}"
check 'TenantConnection' "Found 'TenantConnection' (class deleted in v0.2 — reference the middleware)" "${TARGETS[@]}"
check 'sqlite://' "Found 'sqlite://' URL form (use discrete driver:/path: params instead)" "${TARGETS[@]}"

if [[ $EXIT -eq 0 ]]; then
    echo "docs-lint: OK — no stale v0.1 terms in docs/ or tenancy:init command."
fi

exit $EXIT
