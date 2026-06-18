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

# D-15: fail on bundles.php install-path references in docs/
#
# Phase 22 D-11 replaced the manual "register the bundle in config/bundles.php" install
# instructions with the one-command `tenancy:install` flow. This check guards against
# regression — any future PR that reintroduces "edit config/bundles.php yourself" prose
# in a non-whitelisted docs section fails CI.
#
# Whitelisted H2 sections (legitimate references stay in these scopes):
#   - Migration              — migration recipes from v0.1/v0.2
#   - Upgrade                — upgrade guides
#   - Manual setup           — explicit manual-install fallback sections
#   - Troubleshooting        — diagnostic checks ("is the bundle registered?")
#   - Do I have to do anything?  — profiler-tab.md's optional web-profiler-bundle install path
#   - tenancy:install        — cli-commands.md documents the command's AUTO-MUTATION behavior
#                              (the command's PRIMARY purpose IS to safely edit bundles.php;
#                              describing that is not the regression we're guarding against)
#
# Note: the whitelist is wider than CONTEXT.md D-15's literal text ("Migration / Upgrade only")
# because profiler-tab.md's bundles.php references (under "Do I have to do anything?" and
# "Troubleshooting") document the optional web-profiler-bundle install path, NOT the tenancy
# install regression we're guarding against. Similarly, cli-commands.md's `## tenancy:install`
# H2 documents the command's AST mutation behavior, which is legitimately about bundles.php.
# Restructuring those docs to fit a narrower whitelist would be more disruptive than widening
# the whitelist. See RESEARCH Open Q1 / Landmine #3 for the full rationale.
#
# Implementation: awk tracks the current H2 heading; when in a whitelisted section, body lines
# are skipped before the grep stage. Heading lines themselves never reach the grep (the `next`
# after the section detection ensures this).

BUNDLES_VIOLATIONS=$(awk '
    /^## / {
        section = $0
        sub(/^## /, "", section)
        in_whitelist = (section ~ /^(Migration|Upgrade|Manual setup|Troubleshooting|Do I have to do anything\?|tenancy:install)/)
        next
    }
    !in_whitelist { print FILENAME ":" FNR ":" $0 }
' $(find docs/ -name '*.md') | grep -E 'bundles\.php' || true)

if [ -n "$BUNDLES_VIOLATIONS" ]; then
    echo ""
    echo "ERROR: 'bundles.php' install-path reference found in docs/ outside whitelisted sections"
    echo "       (Migration / Upgrade / Manual setup / Troubleshooting / Do I have to do anything? / tenancy:install)."
    echo "$BUNDLES_VIOLATIONS"
    EXIT=1
fi

# D-04: fail when a docs/ file references "shared entity/entities" without BOTH
# canonical disambiguation phrases ("landlord-side master" AND "tenant-side read-only copy").
# Scoped to docs/ only — UPGRADE.md and CHANGELOG.md are NOT under docs/ and are exempt.
# Per-file AND-logic requires a loop; the flat check() helper cannot do this.

SHARED_ENTITY_VIOLATIONS=""
while IFS= read -r -d $'\0' f; do
    if grep -qiE 'shared entit(y|ies)' "$f"; then
        if ! grep -q 'landlord-side master' "$f" || ! grep -q 'tenant-side read-only copy' "$f"; then
            SHARED_ENTITY_VIOLATIONS="${SHARED_ENTITY_VIOLATIONS}${f}\n"
            EXIT=1
        fi
    fi
done < <(find docs/ -name '*.md' -print0)

if [ -n "$SHARED_ENTITY_VIOLATIONS" ]; then
    echo ""
    echo "ERROR: File(s) reference 'shared entity/entities' without BOTH disambiguation phrases"
    echo "       ('landlord-side master' AND 'tenant-side read-only copy'):"
    printf "%b" "$SHARED_ENTITY_VIOLATIONS"
fi

if [[ $EXIT -eq 0 ]]; then
    echo "docs-lint: OK — no stale v0.1 terms in docs/ or tenancy:init command, and no bundles.php install-path regressions."
fi

exit $EXIT
