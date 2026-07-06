# Parallel Migrations

The `tenancy:migrate` command gained a `--parallel` flag in v0.5 that runs pending Doctrine
Migrations across all tenants concurrently using a bounded subprocess pool. Without `--parallel`,
migrations run sequentially (the v0.4 default), which is safe but slow on fleets of hundreds of
tenants. With `--parallel`, the command spawns up to `--concurrency` subprocesses simultaneously,
each responsible for one tenant, cutting total migration wall-clock time by up to `N×` on a healthy
fleet. Exit codes are propagated correctly — a null or non-zero subprocess exit is always recorded
as `FAILURE`, never silently swallowed.

This page covers the flags added in v0.5, the bounded subprocess behavior, the shared_db guard, the
JSON output shape, and a runbook for safely running parallel migrations during a rolling deploy.

---

## Prerequisites

!!! tip "Requirements for `--parallel`"
    `--parallel` is a flag on the existing `tenancy:migrate` command — it is **not** a separate command.
    To use it, both of the following must be true:

    - `tenancy.database.enabled: true` (database-per-tenant driver); see [Database-per-Tenant](../user-guide/database-per-tenant.md)
    - `doctrine/migrations` installed: `composer require doctrine/migrations`

    The command is silently unavailable if either requirement is missing.

    **`--parallel` does not work under `shared_db`** — the command returns `FAILURE` immediately with
    a clear message before any subprocess is spawned (see the shared_db guard below).

---

## Flags

| Flag | Type | Default | Effect |
|------|------|---------|--------|
| `--parallel` | flag (no value) | off | Enables the bounded subprocess pool. Without this flag, migrations run sequentially (v0.4 behavior). |
| `--concurrency` | `=N` | `4` | Maximum number of concurrent subprocesses. Values `< 1` (or non-numeric) fail with exit code `INVALID`. Values `> 32` are reduced to 32 and a console notice is printed. |
| `--dry-run` | flag (no value) | off | Computes the migration plan without applying it. Works in both sequential and parallel mode. |
| `--format` | `=txt\|json` | `txt` | Output format. `txt` prints human-readable per-tenant output. `json` suppresses all human output and writes only the JSON document to stdout — suitable for CI pipelines. |
| `--tenant` | `=slug` | (all) | Run for a single tenant only. Combining `--parallel --tenant=<slug>` is valid but spawns no pool (one tenant = no parallelism). |

---

## Behavior

- **Bounded subprocess pool**: `--parallel` spawns up to `--concurrency` subprocesses. Each subprocess
  runs the migration for one tenant in isolation. Once a subprocess finishes, the next tenant in the
  queue is picked up, keeping the pool filled until all tenants are processed.
- **Null/non-zero exit = FAILURE**: Any subprocess that exits with a non-zero code, or terminates
  abnormally (null exit), counts as a failed tenant. It is never counted as a success.
- **Continue on failure**: The command does not stop on the first error. All tenants are attempted.
  Failures are accumulated and reported in a summary at the end. Exit code `1` if any tenant failed,
  `0` if all succeeded.
- **Atomic per-tenant output**: In `txt` mode, each tenant's output block is written atomically to
  avoid interleaving. In `json` mode, the full JSON document is written to stdout only after all
  subprocesses have finished.
- **`--dry-run` flows through**: Dry-run mode is passed to each subprocess. The plan is computed
  without applying any migration.

---

## shared_db guard

Running `tenancy:migrate --parallel` when the active driver is `shared_db` is rejected immediately:

```
tenancy:migrate is only available with the database_per_tenant driver. Parallel migration is not supported under the shared_db driver.
```

The command returns `Command::FAILURE` before any subprocess is spawned. This guard exists because
parallel migrations are only meaningful in database-per-tenant mode — in shared_db mode there is one
database shared by all tenants and no per-tenant connection to switch.

---

## Usage

```bash
# Run migrations for all tenants in parallel (default concurrency = 4)
bin/console tenancy:migrate --parallel

# Run with higher concurrency (clamped to 32 if above)
bin/console tenancy:migrate --parallel --concurrency=8

# Dry-run in parallel mode — compute plan without applying
bin/console tenancy:migrate --parallel --dry-run

# JSON output for CI pipelines
bin/console tenancy:migrate --parallel --format=json

# Single tenant (sequential even with --parallel)
bin/console tenancy:migrate --tenant=acme --parallel
```

---

## JSON output shape

When `--format=json` is passed, the command writes a single JSON document to stdout after all
subprocesses complete:

```json
{
  "tenants": [
    {
      "slug": "acme",
      "status": "success",
      "migrationsApplied": 2,
      "durationMs": 143
    },
    {
      "slug": "broken-tenant",
      "status": "failure",
      "migrationsApplied": 0,
      "durationMs": 12,
      "error": "Connection refused: mysql:host=db-host;dbname=broken_db;user=REDACTED"
    }
  ],
  "summary": {
    "succeeded": 1,
    "failed": 1,
    "total": 2,
    "wallClockMs": 156
  }
}
```

The `error` field is present only on failed tenants. It contains the last non-empty output line
from the migration subprocess, with HTML tags stripped and UTF-8 scrubbed — but **no credential
redaction is performed**. The migrate path does not use `HealthResponseSanitizer`. If the
subprocess fails with a database connection error, the raw DSN (including host and credentials)
may appear in the `error` field. Treat migration JSON output as sensitive — scrub it before
persisting to logs or dashboards.

---

## Runbook: rolling fleet migration during a deploy

**Scenario (D-02):** You are doing a rolling deploy. The new code requires a Doctrine migration.
You need all tenant databases to be migrated before (or while) the new pods start serving traffic.

**Steps:**

1. **Before deploying the new image**, run migrations in parallel from a deploy runner or a
   dedicated migration pod using the old image:

    ```bash
    bin/console tenancy:migrate --parallel --concurrency=8 --format=json | tee migration-run.json
    ```

2. **Check the exit code**. Exit `0` = all tenants migrated. Exit `1` = at least one failure.

    ```bash
    echo "Exit code: $?"
    ```

3. **On failure**, inspect `migration-run.json` for the failed tenants and their errors. Retry
   only the failing tenants using `--tenant`:

    ```bash
    bin/console tenancy:migrate --tenant=broken-tenant
    ```

4. **After all migrations succeed**, proceed with the rolling deploy. New pods boot against
   already-migrated databases.

5. **If a migration introduces a backwards-incompatible schema change** (column drop, constraint
   add), ensure the new application code is deployed BEFORE applying that migration, or use a
   multi-step migration strategy (expand/contract). The `--dry-run` flag helps verify the plan
   before applying:

    ```bash
    bin/console tenancy:migrate --parallel --dry-run
    ```

6. **For canary deploys**: run `--tenant=canary-slug` first, verify application health on the
   canary pod, then run `--parallel` for the remaining fleet.

!!! warning "Concurrency and database connections"
    Each subprocess opens a new database connection for its tenant. With `--concurrency=32` on a
    fleet of 200 tenants, you will have up to 32 simultaneous connections open at peak. Size your
    database connection pool accordingly, or use a lower concurrency value if connections are
    constrained.

---

## See Also

- [CLI Commands](../user-guide/cli-commands.md#tenancy-migrate) — base `tenancy:migrate` command reference
- [Maintenance Mode](maintenance-mode.md) — enabling maintenance during a deploy
- [Health Checks](health-checks.md) — monitoring tenant health after migrations
