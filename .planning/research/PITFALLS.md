# Pitfalls Research — v0.3 Adoption Surface

**Domain:** Symfony multi-tenancy bundle (`danplaton4/tenancy-bundle`), v0.3 adoption-surface features added to a published v0.2 bundle
**Researched:** 2026-05-15
**Confidence:** HIGH for technical findings (Symfony Mailer/Profiler/Messenger internals verified against official docs and source-cited GitHub issues); MEDIUM for cross-OS demo-app failure modes (verified via vendor bug trackers, not empirically reproduced)

> **Scope note.** This research only covers the *new attack surface* introduced by v0.3 (`tenancy:install`, demo app, profiler tab, Mailer bootstrapper, OriginHeaderResolver) and the operational/governance risks of adding adoption features to an already-published bundle. The general bundle pitfalls (identity-map pollution, DBAL reset, SQL filter bypass, etc.) live in `.planning/milestones/v0.2-research/PITFALLS.md` and are still in force — this file does not re-derive them. Where a v0.3 feature *intersects* an existing pitfall (e.g., the Mailer bootstrapper inherits the Messenger worker teardown pitfall), the intersection is called out explicitly.
>
> **Governing lesson from v0.2** (issues #5–#8, retraction of v1.0.0): a defect that compiles cleanly and only manifests downstream is a tag-retraction event. The v0.3 features are *exactly* the surface that downstream users see first. Apply the issue #5 lesson — **codify the invariant at compile time** wherever the contract is enforceable in the container — to every v0.3 feature where the failure mode is "looks fine in our tests, breaks in someone else's project."

---

## Critical Pitfalls

### Pitfall 1: Mailer transport overridden at dispatch-time instead of send-time → async emails go to wrong tenant

**What goes wrong:**
The Mailer bootstrapper (BOOT-04) overrides the active SMTP transport when `TenantResolved` fires. In the sync mailer path this is correct: `$mailer->send()` resolves the transport immediately, the tenant is still active, the right SMTP is used.

In the **async** path it is silently wrong. When `symfony/mailer` is configured to route `SendEmailMessage` to a Messenger transport (the recommended default), `$mailer->send()` dispatches a serialized message to the queue and returns. The Messenger worker consumes the message in a **separate process**, in which:

1. The original request's `TenantContext` does not exist.
2. The handler reconstructs tenant context from the `TenantStamp` carried on the envelope (this already works — Phase 06).
3. The handler then asks the Mailer service for *its* transport — which was wired at container compile time as the **default landlord transport**, because nothing in the worker process has yet swapped it.

Result: the email body says "Welcome to Acme Corp" but the SMTP that sends it is the landlord's SES account, with the landlord's `From:` header, and (if rate-limit-segregated) the landlord's reputation. In multi-tenant SaaS this is a customer-visible incident — DKIM mismatches, replies routed to the wrong inbox, support tickets ("why did my customer get an email from `noreply@platform.example.com`?").

This is the v0.3 equivalent of **issue #5** (decorator-contract incompleteness): the bootstrapper looks complete because the sync test passes, but a downstream user with `messenger.transports.async: "%env(MESSENGER_TRANSPORT_DSN)%"` and async-routed Mailer hits the failure mode on first send.

**Why it happens:**
Two layered Symfony architectural facts make this counter-intuitive:

1. **`SendEmailMessage` rendering is deferred.** Per Symfony Mailer docs and `symfony/mailer` source, when async, the "rendering" of the email (computed headers, body rendering, transport selection) is deferred until the Messenger handler runs. The handler is `Symfony\Component\Mailer\Messenger\MessageHandler`, and it calls `$transport->send($message, $envelope)` on the transport injected at construction time.
2. **`MessageEvent` cannot change the transport.** The `RawMessage` on `MessageEvent` is a clone in some dispatch paths (see [symfony/symfony#34972](https://github.com/symfony/symfony/issues/34972)), and the transport itself is selected **before** `MessageEvent` is dispatched. Listeners that attempt `$event->setEnvelope()` or mutate transport DSN are fighting the framework.

The bootstrapper-author's mental model — "I'll override the mailer at boot, just like the cache adapter" — works for cache because cache resolves at call-time. It fails for Mailer-via-Messenger because the actual transport decision is made in the worker, by a service whose construction parameters were locked at compile time.

**How to avoid — recommended extension point:**

There are three viable extension points, in **strict preference order**:

1. **`X-Transport` header at dispatch + multi-transport mailer config** (RECOMMENDED for v0.3).
   - At dispatch time, the Mailer bootstrapper installs a `MessageEvent` listener that reads `TenantContext`, looks up the tenant's transport *name* (not DSN), and sets header `X-Transport: tenant_<slug>` on the message.
   - User configures `framework.mailer.transports` with one named transport per tenant — or per tenant *group* — using Symfony's existing multi-transport routing (`X-Transport` is the supported selection header, per [symfony/symfony#46372](https://github.com/symfony/symfony/discussions/46372)).
   - The header is part of the serialized message; it survives the queue; the worker's `Mailer` resolves transport by header at send-time.
   - **Why this is safe:** zero reflection, zero container mutation, uses Symfony's documented selection mechanism. The transport-name → DSN mapping is in user config, which is the right place — DSNs with credentials never touch our code.

2. **`TenantStamp`-aware `TransportFactory` decoration** (FALLBACK for dynamic DSN — e.g., when tenants are not enumerable at compile time).
   - Decorate `mailer.transports` (the `Transports` collection service). The decorator, on `send($envelope, $message)`, reads the current `TenantContext` (which is already restored by `TenantWorkerMiddleware` in the worker thanks to the `TenantStamp`), resolves a DSN from a `TenantTransportProviderInterface`, and instantiates a transport via `Transport::fromDsn()`.
   - **Must cache the resolved transport per tenant slug** inside the decorator (LRU, bounded size) — `Transport::fromDsn()` re-establishes SMTP/HTTP connections; doing it on every email is a perf cliff.
   - **Must invalidate cache on `TenantContextCleared`** to prevent leaking a tenant's SMTP socket into a subsequent message for a different tenant in the same worker.

3. **`MessageEvent` with envelope mutation** (NOT RECOMMENDED — listed only to explicitly reject):
   - You can mutate `$event->getEnvelope()->setSender(...)` to change the `From:`, but **you cannot change the transport** at this point.
   - This is the trap most blog posts fall into. The transport is already selected.

**Compile-time guard (issue #5 lesson):**
Add a `MailerTransportContractPass` that asserts:
- If `BOOT-04` is enabled (mailer config present) AND `interface_exists(\Symfony\Component\Mailer\MailerInterface::class)`, then **either** (a) at least one `X-Transport`-routed transport is configured in `framework.mailer.transports`, OR (b) a service implementing `TenantTransportProviderInterface` is registered.
- If neither is true, the bundle is in "mailer enabled but no transport resolution strategy" state — fail compile with `LogicException` and link to docs.

Also assert: if Messenger is installed AND mailer is routed async (detectable by inspecting `messenger.routing` config), then strategy #1 (`X-Transport` header) MUST be active, because strategy #2 with a worker that processes other tenants' messages between Mailer.send and SMTP-dispatch is async-unsafe unless the decorator is wrapped by `TenantWorkerMiddleware` (which it would be, but the audit trail is easier to verify with a compile-time check).

**Warning signs (detection):**
- Integration test: dispatch an email in tenant A context with async transport, run worker, assert the SMTP transport the worker used has DSN matching tenant A's mailer config. **This is the canary test** — write it before writing the bootstrapper.
- Worker logs grep for tenant slug in From: header → mismatched against `TenantStamp` slug
- DKIM signing keys: if the landlord domain signs an email whose `From:` is a tenant domain, MTAs will drop it (SPF/DKIM failure). User reports will be "emails not arriving".
- `Mailer\Transport\TransportException: Unable to authenticate` from tenant A's worker run after a healthy tenant B run → cached/leaked SMTP socket

**Phase to address:** Phase BOOT-04 (Mailer bootstrapper). **Must include:**
- Async canary test (above) as a phase quality gate
- `MailerTransportContractPass` as a compile-time guard
- Documentation page comparing the three strategies, with the recommendation explicit

---

### Pitfall 2: `tenancy:install` corrupts a user's `config/bundles.php`

**What goes wrong:**
The `tenancy:install` command (DX-06) mutates a file in the user's project that they did not write recently and may have edited heavily. The Symfony default `config/bundles.php` is a literal PHP array literal returned by `return [ ... ];`, but a non-trivial fraction of real projects have:

- **DDD/Hexagonal layouts** where `bundles.php` is replaced by a `Kernel::registerBundles()` override that does not use the file at all.
- **Conditional bundle loading** (`if ('dev' === $env)` blocks).
- **Comments and trailing commas** inserted by other tools.
- **Custom array keys** (`SomeBundle::class => ['all' => true, 'when@worker' => false]`).
- **Multi-line bundle declarations** for readability.

Naive append-via-`file_put_contents(..., FILE_APPEND)` will produce a syntactically invalid file the moment any of these patterns are present. Naive regex insertion will produce a syntactically valid but semantically wrong file (insertion inside the wrong array, after the closing `];`, etc.).

**This is the user's code. We do not get to break it.** A user whose `config/bundles.php` is corrupted on first install will not file an issue — they will `composer remove danplaton4/tenancy-bundle` and tell their team to use the manual instructions. That is an irrecoverable adoption loss.

**Why it happens:**
- Symfony Flex normally does this via a recipe and PHP-AST manipulation, but **v0.3 explicitly has no Flex recipe** (per PROJECT.md and the `feedback_no_flex.md` memory). The bundle must do this work itself, without the recipe infrastructure.
- The simplest implementation (string concat, regex, sed) seems to work on a fresh `symfony new` skeleton and fails the moment it meets a real project.
- AST manipulation (`nikic/php-parser`) is correct but adds a runtime dependency that is heavy for a single command.

**How to avoid:**

1. **Detect-and-instruct, don't blindly mutate.**
   - First action: parse `config/bundles.php` and check whether `TenancyBundle::class` is already present. If yes — print "already registered, skipping" and exit 0. **Idempotent on re-run is non-negotiable.**
   - Second: if the file is *not* the standard Symfony Flex-generated shape (we can detect by checking: file exists, returns array literal, all keys are `::class` constants, no statements other than the return) — **refuse to mutate**, print the manual snippet, and exit 0 (NOT a failure). Tell the user "your `bundles.php` is non-standard; add this line manually: …".
   - Third: if `bundles.php` does not exist (e.g., the project uses `registerBundles()` override): detect this by checking `Kernel.php` for `registerBundles()` override OR the absence of `MicroKernelTrait` use → print the manual snippet for `registerBundles()`, exit 0.
   - Only in the fourth case — file exists, is standard shape, our entry is absent — do we mutate.

2. **Mutate via `nikic/php-parser`, not via regex.**
   - `nikic/php-parser` is the de facto standard for safe PHP-AST mutation. It is already a transitive dependency of `symfony/maker-bundle`, which most users have. We can `require` it as a `require-dev` of the bundle and only load it in the `tenancy:install` code path (guarded by `class_exists`).
   - Read file → parse to AST → find the `return` statement's array → check if our entry exists → if not, append an `ArrayItem` → print AST back to file via `Standard` pretty-printer.
   - **Preserve formatting** by using the `cloneNodes: false` option to retain attributes; the pretty-printer respects original indentation reasonably well.

3. **Always write atomically.**
   - Write to `config/bundles.php.new`, fsync, then `rename()` to `config/bundles.php`. A SIGTERM mid-write must not leave a half-written `bundles.php`. Use `Filesystem::dumpFile()` from `symfony/filesystem` — it does the atomic rename for us.

4. **Always create a backup.**
   - `cp config/bundles.php config/bundles.php.bak.$(timestamp)` before mutating. Print the backup path in the command output so the user can roll back. Keep at most 3 backups (delete oldest).

5. **Encoding and line-endings.**
   - Detect BOM (UTF-8 BOM = `\xEF\xBB\xBF`) at file head — if present, preserve it.
   - Detect line endings (`\r\n` vs `\n`) by sampling — write back in the same convention.
   - `nikic/php-parser` Standard pretty-printer uses `\n` by default; we must post-process for `\r\n` files on Windows.

6. **Permissions.**
   - Don't `chmod` the file after writing. Whatever permissions the user had, preserve. `dumpFile()` does this correctly via `rename`.
   - On Windows CI runners, file locks (e.g., from an editor open on the file) can cause rename to fail — catch `IOException`, restore from backup, print clear message.

**Compile-time guard:** Not applicable (runtime command, not service wiring). Instead:

**Runtime guards (the equivalent for a command):**
- `tenancy:install --dry-run` mode that prints the diff but writes nothing. Test the dry-run output in CI against fixture `bundles.php` files for: fresh skeleton, project with comments, project with already-installed bundle, project with conditional load, DDD layout, missing file, syntactically invalid file.
- After mutation, `php -l config/bundles.php` (syntax check) before declaring success. If syntax check fails, automatically restore the backup and exit with error message + backup path.

**Warning signs (detection):**
- Test corpus: a directory of `bundles.php` fixtures collected from real projects (Symfony skeleton, API Platform skeleton, Sulu, DDD example). Run `tenancy:install` against each in a temp dir; assert each produces a parseable, semantically-correct result OR refuses cleanly.
- Property-based test: generate random valid `bundles.php` content via php-parser, run install, assert resulting file parses and contains our entry exactly once.
- Re-run idempotency: `tenancy:install && tenancy:install && tenancy:install` produces the same final file.

**Phase to address:** Phase DX-06 (`tenancy:install`). The "test corpus of fixture `bundles.php` files" is a phase quality gate — **must include at least 6 distinct fixture types** before phase is callable done. The dry-run mode is a separate testable surface and should be in the plan's scope explicitly, not added later.

---

### Pitfall 3: Profiler data collector serializes a non-serializable `TenantContext` or its dependencies

**What goes wrong:**
The DX-02 profiler tab collects "active tenant, ID, connection, resolved-by" at request-end time. The naive implementation injects `TenantContext` into the collector and stores `$this->data = ['context' => $context]`. The profiler then attempts to serialize `$this->data` to the filesystem for later viewing (`/_profiler/<token>` URL). Two failure modes:

1. **Non-serializable state in the graph.** `TenantContext` itself is a zero-dep value holder (good), but the `Tenant` entity it points to may be a Doctrine proxy with a closure-bound `EntityManager` — proxies are not always serializable, and even when they are, deserializing them on the profile-view request will fail (EM no longer present, lazy-load throws).
2. **Cleared-context blank panel.** The bundle's design (CORE / Phase 01) clears `TenantContext` on `kernel.terminate`. Symfony profile serialization happens during `kernel.terminate`. **Order matters.** If our `TenantContextClearedListener` runs *before* the profiler's serialization (priority race), the collector serializes a null tenant and the panel shows empty even for a request that had a tenant active throughout.

The combination produces a confusing first-impression bug: profile says "no tenant" on a request that was *clearly* tenant-scoped (the response body contains tenant data). The dev's first reaction is "the bundle is broken" — and the first impression is poisoned.

**Why it happens:**
- Devs implementing data collectors copy from Symfony docs that say "store data in `$this->data`" and don't realize `$this->data` is `serialize()`d.
- The `LateDataCollectorInterface::lateCollect()` exists *exactly* for this case (per [Symfony docs](https://symfony.com/doc/current/profiler/data_collector.html)) but is easy to miss — the regular `collect()` runs on `kernel.response`, before our `kernel.terminate` clear runs, so a naive impl works in dev tests but breaks on stored profiles.
- The clear-vs-collect priority race is silent and timing-dependent — sometimes panel shows data, sometimes blank, depending on listener registration order.

**How to avoid:**

1. **Collect at `collect()` (kernel.response), not `lateCollect()`** — the tenant context is still active at `kernel.response`. `lateCollect()` runs during `kernel.terminate`, and our `TenantContextOrchestratorListener` is registered on `kernel.terminate`. To avoid the priority race entirely, collect early.

2. **Store only scalar/array data, never objects.**
   - Store: `['tenant_id' => string, 'tenant_slug' => string, 'resolved_by' => string, 'driver' => string, 'connection_name' => string|null, 'resolution_time_ms' => float|null]`.
   - Never store: `Tenant` entity, `TenantResolution` object, `TenantContext` itself, anything that could carry a reference to `EntityManager`, `Connection`, or `Container`.
   - Rule of thumb: if a value would not survive `json_encode → json_decode`, do not put it in `$this->data`.

3. **Implement `reset()` correctly** — `DataCollectorInterface` extends `ResetInterface` in modern Symfony; resetting must clear `$this->data` to a known-empty shape, so a long-running test/dev server doesn't carry one request's data into the next collector instantiation.

4. **Render robustly** — the Twig template MUST handle the "no tenant" case (`tenant_id is null`). The WDT icon should:
   - Show a small "T" icon with the tenant slug when present.
   - Show a greyed icon with "no tenant" tooltip when the request resolved without one (public/landlord/health route — this is the legitimate "no tenant" case, post-FIX-02).
   - **Never hide entirely** — the absence of the icon is indistinguishable from "the bundle is broken". Always present.

5. **Production-mode compile-out.**
   - Data collectors must register their service only in `dev` / `test` (`when@dev`, `when@test` in service config), or be guarded by `framework.profiler.enabled`. A production deployment must not pay any cost for the collector.
   - The Twig template lives in `Resources/views/Collector/` and is auto-discovered by `WebProfilerBundle` — this only loads when `web_profiler.enabled = true`, which is dev-only by default. Good. Still, register the service conditionally to be defensive.

6. **No queries in `collect()` or `lateCollect()`.** Collector code path runs on every dev request. Don't `$tenantProvider->findAll()` to render a count; don't `$em->createQuery(...)`. Snapshot what was already in memory.

**Compile-time guard:**
A `ProfilerCollectorContractPass` that, when `WebProfilerBundle` is registered, verifies our collector service is tagged `data_collector` correctly AND has the right template path. Less essential than the Mailer pass — failure here is just "no panel shows up", not data corruption. **Skip unless it's a 10-line check.**

**Warning signs (detection):**
- Test: dispatch a request with tenant, store the profile to disk, reload `/_profiler/<token>`, assert tenant info renders. **This catches the serialization bug.**
- Test: dispatch a request without tenant (landlord route), assert panel renders "no tenant" not blank.
- Test: dispatch a request, `assertCount(0, $em->getQueryLog()->getQueriesAfter($collectorCalled))` — collector adds zero queries.
- Test: register a listener with priority `INT_MIN` on `kernel.terminate` that asserts `$tenantContext->getActiveTenant() === null`. This proves clear happened. If profile still shows tenant data, lateCollect was used (good) — if blank, collect() raced.

**Phase to address:** Phase DX-02 (Profiler tab). The "panel must render in three states" (tenant resolved, no tenant resolved, error during resolution) is a quality gate.

---

### Pitfall 4: Demo app's "subdomain on localhost" works on the author's laptop and nowhere else

**What goes wrong:**
The DEMO-01 demo app demonstrates the `HostResolver` by routing `tenant1.localhost` and `tenant2.localhost` to two different tenants. The author tests it locally with `curl -H "Host: tenant1.localhost"` and it works. They push, ship the README "just run `docker compose up` and visit `http://tenant1.localhost:8080`."

**It does not work on the user's machine** because:

1. **Firefox**, until very recently, did **not** resolve `*.localhost` to 127.0.0.1 by default ([Mozilla bug 1741109](https://bugzilla.mozilla.org/show_bug.cgi?id=1741109), [bug 1686759](https://bugzilla.mozilla.org/show_bug.cgi?id=1686759), [bug 1433933](https://bugzilla.mozilla.org/show_bug.cgi?id=1433933)). Some Firefox versions resolve, some don't; behavior depends on `network.dns.offline-localhost` pref. Even in 2026, the user's specific Firefox configuration may not resolve.
2. **Safari** has had long-standing issues with subdomains of `.localhost` ([WebKit bug 160504](https://bugs.webkit.org/show_bug.cgi?id=160504)).
3. **Chrome** correctly resolves `*.localhost` per RFC 6761. This is what the author tested.
4. **Linux distros** vary: glibc resolves `*.localhost` if `nss-myhostname` is enabled (default on Arch, Fedora), but some Debian/Ubuntu containers do not unless `/etc/hosts` has entries.
5. **Docker Desktop on macOS / Windows** routes `*.localhost` from the host to Docker correctly, but DNS resolution inside containers (e.g., when the demo's tests run inside Docker hitting the demo app via subdomain) goes through container DNS, which often **does not** resolve `*.localhost`.
6. **WSL2** has its own DNS quirks where `tenant1.localhost` from a WSL2 shell may not resolve to the Windows host's 127.0.0.1.

The user opens Firefox, types `http://tenant1.localhost:8080`, gets a DNS error, files an issue: "Demo doesn't work." This is the first impression. The bundle's actual code is fine — the demo's *DNS assumption* is the bug, but the user can't tell.

**This is the v0.3 equivalent of "it works on my machine, not on theirs."** And because the demo is the bundle's onboarding ramp, this defect is high-leverage in the wrong direction.

**Why it happens:**
- The author uses Chrome, tests on Chrome, calls it done.
- Cross-browser/cross-OS testing of demo apps is not part of the bundle's CI surface.
- RFC 6761 *requires* implementations to treat `localhost` as a special name, but the subdomain-resolution interpretation is ambiguous and browsers/OSes differ on whether `*.localhost` is implied.

**How to avoid:**

1. **Default to host-header path, not real DNS.**
   - The demo's curl/HTTPie snippets should be `curl -H "Host: tenant1.localhost" http://localhost:8080` — works on every machine, every browser-independent. Browser snippets are *additional*, with explicit caveats.

2. **Document the three-step fallback ladder** in the demo README:
   - **Step 1 (works everywhere):** Use the `Host:` header via curl/HTTPie or use a browser extension like "ModHeader" to inject `Host:` on a request to `http://localhost:8080`.
   - **Step 2 (works on macOS/Linux):** Add to `/etc/hosts`: `127.0.0.1 tenant1.localhost tenant2.localhost`. Same on Windows in `C:\Windows\System32\drivers\etc\hosts`.
   - **Step 3 (browser-native, Chrome only by default):** Use `http://tenant1.localhost:8080` directly. **Explicitly note Firefox/Safari may not resolve and the workaround.**

3. **Provide a smoke script** in `examples/`: `bin/smoke.sh` runs the four resolver scenarios via curl with `Host:` header injection and exits non-zero if any tenant route fails to be routed correctly. This is what runs in CI. The smoke script is the *real* "does the demo work" test, not a browser visit.

4. **CI gate the demo.**
   - GitHub Actions job: `docker compose up -d`, wait for health, run `bin/smoke.sh`, assert both tenants resolve correctly. If this job fails, the bundle does not release. This converts "demo works" from a manual claim to a CI-enforced invariant.

5. **Demo path repo composer cache** — when the demo's `composer.json` references the bundle via `repositories: [{ type: path, url: "../" }]`, composer creates a symlink to the bundle source. **By default `--prefer-source` is symlinked, BUT** if the user runs `composer install --prefer-dist`, composer **copies** the bundle into `vendor/` and the demo no longer reflects local bundle changes. README and the docker-compose file's startup command must default to `--prefer-source` AND document the gotcha. Add a smoke-script assertion: after `composer install`, `readlink vendor/danplaton4/tenancy-bundle` exists.

6. **Docker volume permission hazards.**
   - SQLite/MySQL data volumes: do not mount onto a host path — use named volumes only. Host-path mounts on Linux create files owned by container UID (often 999), which break on user's `rm -rf`. Named volumes are managed by Docker, no host permission concerns.
   - Don't ship a docker-compose that mounts `./var:/var/www/var` — every user with a different host UID will hit permission errors. Use named volumes.

7. **First-run footguns.**
   - Port conflict: README explicit that ports 8080, 3306 must be free; provide `.env` override.
   - MySQL init delay: use `condition: service_healthy` in compose `depends_on`, with a `healthcheck` on the DB. Never rely on `sleep`.
   - No wait-for-it scripts: use Docker Compose v2's native `condition: service_healthy`.

**Compile-time guard:** Not applicable (demo is separate from bundle). **Workflow guard:**
- The `examples/` demo path is added to the bundle's CI matrix. The job is named `demo-smoke` and is **required** for merge to `master`. Without this gate, the demo silently rots over time. This is the "demo CI gate" from the quality criteria.

**Warning signs (detection):**
- User issues with title containing "demo", "localhost", "doesn't resolve" — track and route to demo README fix
- CI smoke job failure on any tenant route — block release
- README walkthrough goes stale: add a CI job that lints the README's curl commands against the actual route definitions

**Phase to address:** Phase DEMO-01. Smoke script + CI gate are quality gates, not optional. README must include the three-step fallback ladder before phase is callable done.

---

### Pitfall 5: OriginHeaderResolver trusts a browser-controlled header for tenant identification

**What goes wrong:**
The RESV-06 `OriginHeaderResolver` lets SPAs identify their tenant via the `Origin` header, which is the natural identifier when frontend and backend are on different domains. The naive implementation reads `$request->headers->get('Origin')` and looks up a tenant whose configured origin matches.

This is correct for SPA UX but introduces two security-shaped failure modes:

1. **Spoofable from non-browser clients.** The `Origin` header is set by the browser per spec, and a browser cannot lie about it cross-origin (that's the point of the same-origin policy). But **server-to-server requests, curl, Postman, mobile apps, malicious clients** can set any `Origin` they want. If our resolver trusts `Origin: https://tenant-a.example.com` from any client, and tenant-A's data is gated only by tenant resolution + authentication, an attacker who has a tenant-B user credential can authenticate as tenant-B, set `Origin: https://tenant-a.example.com`, and access tenant-A's API. The CORS policy doesn't help — CORS protects browser-originated requests, not server-originated ones.

2. **CORS preflight (`OPTIONS`) requests** are unauthenticated by design (no `Authorization` header allowed in preflight). They carry an `Origin`. If our resolver fires on preflight, it needs to either (a) resolve a tenant and proceed (preflight is safe — it returns CORS headers, no data), or (b) skip resolution for preflight. Failing to handle preflight breaks the SPA's CORS flow entirely — the actual request never fires because preflight returned 401.

There's a third, more subtle:

3. **Resolver order ambiguity.** Both `OriginHeaderResolver` and the existing `HeaderResolver` (`X-Tenant-ID`) can fire on the same request. If a request carries both `Origin: https://tenant-a.example.com` AND `X-Tenant-ID: tenant-b`, which wins? Without a documented contract, the answer depends on resolver priority, which depends on user config. A misconfiguration leads to the wrong tenant being resolved and the user blaming the bundle.

**Why it happens:**
- Devs implementing SPA-friendly resolvers see `Origin` documented as "set by the browser" and assume it's trustworthy. The spec is "set by the browser **in browser-originated requests**" — server-originated requests have no such guarantee.
- CORS preflight is invisible during ordinary development (browsers cache preflight responses 24h) and only becomes a problem on first deploy or first new SPA build.
- Resolver-chain order is documented but easy to misconfigure, and the symptom (wrong tenant for some routes) is hard to attribute to resolver order vs. cache vs. session.

**How to avoid:**

1. **Document the trust model explicitly.**
   - The Origin header IS trustworthy *for browser-originated requests where the same-origin policy is enforced*. It is NOT trustworthy in general.
   - **Recommend:** `OriginHeaderResolver` should be combined with authentication. Tenant resolution by Origin + auth by JWT/session bound to the user → security comes from the cross-check (user must belong to tenant). Resolver alone is not access control.
   - **Document anti-pattern:** Using OriginHeaderResolver without any authentication layer ("anonymous tenant API"). Refuse to enable without a warning log.

2. **Configurable allow-list of origins.**
   - The resolver MUST take a configured allow-list of `origin → tenant slug` mappings. It MUST NOT do a substring/wildcard match by default. `https://attacker.com.tenant-a.example.com` must not match `https://tenant-a.example.com` — use exact equality with URL parsing, not string `endsWith`.
   - For wildcard support (multi-region tenants), use a parsed-URL match: scheme + host + port must all match, host can have ONE configured wildcard at the left-most label position (`*.tenant.app`). Do not allow `*` in the middle.

3. **Handle CORS preflight explicitly.**
   - On `OPTIONS` method requests with `Access-Control-Request-Method` header present (the preflight signature), resolve the tenant *if possible* but do NOT fail if no tenant — preflight responses don't need tenant context, just CORS headers. The orchestrator's null-resolution path (post-FIX-02) already handles this gracefully; the resolver just needs to not throw for preflight.
   - Better: register a `kernel.request` listener at higher priority than tenant resolution that early-responds to CORS preflight without consulting the tenant chain. Symfony's `NelmioCorsBundle` or `nginx`-level CORS handling already does this — document the recommended pattern.

4. **Resolver chain order — explicit and documented.**
   - Default priority: `OriginHeaderResolver` priority 10, lower than `HostResolver` (priority 30) and `HeaderResolver` (priority 20). Reasoning: `Host` is the most authoritative (DNS-bound to the tenant), `X-Tenant-ID` is explicit-by-the-client (suggests deliberate API client), `Origin` is the SPA convenience case. Document this ordering in the resolver's docblock and in `tenancy.yaml` defaults.
   - When both `Origin` and `X-Tenant-ID` are present and resolve to *different* tenants, log a warning (not error — could be legit testing flow) and use the higher-priority resolver's result. **Add an explicit `strict_origin_match: true` config option that raises an exception in this case** for security-sensitive deployments.

5. **`Origin: null` handling.**
   - `Origin` can be `null` for `file://`, sandboxed iframes, redirected requests, some privacy-mode browsers. Fail-safe: when `Origin: null`, the resolver returns `null` (no resolution) — chain proceeds to other resolvers. Do NOT match `null` against any tenant.

**Compile-time guard:**
A `OriginHeaderResolverConfigPass` that, if the resolver is enabled, requires the allow-list config to be non-empty AND validates each entry is a parseable absolute URL (no `*` mid-string, no missing scheme). Fail container compile with the offending entry. Same pattern as the cache-decorator pass — push the failure earlier.

**Warning signs (detection):**
- Security test: dispatch a curl request with `Origin: https://tenant-a.example.com` from outside any browser context, attempt to access tenant-A data, assert the tenant-A user's auth context is NOT granted automatically. The auth layer should reject. If it doesn't, the bundle's docs MUST scream about it.
- Test: dispatch `OPTIONS /api/...` with `Access-Control-Request-Method: POST`, assert it returns a CORS-headers response without going through tenant lookup.
- Test: dispatch request with both `Origin` and `X-Tenant-ID` pointing at different tenants, assert documented winner is used AND a warning is logged.
- Test: configured allow-list `["https://tenant-a.example.com"]`, dispatch `Origin: https://attacker.com.tenant-a.example.com`, assert no resolution.

**Phase to address:** Phase RESV-06. The security model documentation is a quality gate — phase is not done until the README has a "When to use OriginHeaderResolver" section that explicitly states the trust model and the auth-layer-required pattern.

---

### Pitfall 6: Plan↔summary drift returns; `human_needed` items quietly accumulate

**What goes wrong:**
The v0.2 retrospective ([RETROSPECTIVE.md](.planning/RETROSPECTIVE.md)) called out two recurrent governance failures:
1. Four plans (09-03, 09-04, 11-04, 11-05) shipped artifacts but never had `SUMMARY.md` written; caught only at milestone close.
2. Three phases (09, 10, 12) shipped with `human_needed` VERIFICATION items that were never followed up on. Days latent: 7–42.

These are not coding pitfalls — they are workflow defects in our own process. v0.3, with its tight scope and adoption focus, has even less margin: a `human_needed` item that doesn't resolve before tag is **an unverified claim shipping to users**.

In v0.3 these defects compound, because the very features being shipped (demo, install command, profiler) are themselves the verification surface. If `tenancy:install` has `human_needed: "needs verification in fresh project"`, and that verification doesn't happen before tag, the failure mode is **the demo doesn't work for users**.

**Why it happens:**
- `audit-open` checks phase UAT / debug / quick tasks but not plan↔summary parity (per RETROSPECTIVE.md).
- `human_needed` has no TTL — it persists silently.
- The orchestrator doesn't enforce "every plan must have a SUMMARY before the next plan starts."

**How to avoid:**

1. **Carry forward the v0.2 retrospective action items as v0.3 quality gates (not future intentions):**
   - Plan↔summary parity check added to `audit-open` tooling **in Phase 1 of v0.3** (before any v0.3 feature plans run). Treat it as the prerequisite-phase work.
   - `human_needed` VERIFICATION status auto-converts to a Known Gap entry after 72 hours OR blocks milestone close, whichever first.

2. **Pre-tag checklist as a hard gate** — before tagging v0.3.0:
   - `audit-open` returns zero findings
   - Every plan has a SUMMARY (parity check)
   - Every `human_needed` is resolved or escalated
   - Demo CI smoke job is green on the merge commit
   - At least one of (`tenancy:install` dry-run tested on 6 fixture types) is documented as completed
   - The retrospective carry-forward items from v0.2 are themselves verified done

3. **Pessimistic verification**: for v0.3 features that touch user files (`tenancy:install`), the verification step is "run against a corpus of real user projects" — not "verify on author's machine." This is the v0.3 equivalent of the v0.2 lesson "test it on someone else's setup."

**Compile-time guard:** Not applicable — this is a process pitfall. Workflow guards apply:
- `gsd-sdk` exit-code-1 on missing summaries
- `audit-open` enforced as a release-gate command (not advisory)

**Warning signs (detection):**
- `audit-open` returns findings on the merge commit for a release tag
- Any `human_needed` VERIFICATION older than 72 hours
- Any plan that has phase verification but no SUMMARY.md
- Any milestone-close planning artifact with retroactive backfill date stamps

**Phase to address:** Phase 0 / kickoff (process work, before feature work). The retrospective carry-forward items are NOT a v0.3 feature — they are v0.3's *foundation* and should run first. If they slip to mid-milestone, the same failures recur.

---

## Moderate Pitfalls

### Pitfall 7: Mailer DSN with credentials leaked via exception trace or log

**What goes wrong:**
When a per-tenant SMTP transport fails (auth error, network timeout, DNS), the exception thrown by `symfony/mailer` includes the DSN — including username and password — in the message. This propagates to Sentry, Symfony's monolog handler, the browser (in dev), and any error tracking surface. A user's SMTP credentials are now in our error stream.

**How to avoid:**
- Wrap any Mailer-bootstrapper-originating exceptions in a sanitizing exception class that scrubs DSN-shaped strings (`smtp://user:pass@host:port` → `smtp://[REDACTED]@host:port`).
- Ensure the resolved transport object's `__toString()` doesn't echo credentials.
- Test: trigger an auth failure on a tenant's SMTP, capture the exception, assert no password substring in the message or trace.

**Phase to address:** Phase BOOT-04.

---

### Pitfall 8: Mailer's `Transports` internal cache leaks transports across tenants

**What goes wrong:**
`Symfony\Component\Mailer\Transport\Transports` (the multi-transport selector) holds an array of named transports built at compile time. If we install a dynamic per-tenant transport via decoration, and the decorator caches transports keyed by tenant slug, the cache must be **strictly bounded** (LRU) and **must clear** on `TenantContextCleared` to release SMTP sockets. A long-running worker handling 1000 tenants in rotation will OOM if every SMTP connection is held open indefinitely.

**How to avoid:**
- LRU cache with max-entries config (default 8 — covers most worker patterns).
- Explicit `clear()` method on the decorator called from `TenantContextCleared` listener.
- Test: process 50 different-tenant messages in a worker, assert memory footprint is bounded.

**Phase to address:** Phase BOOT-04.

---

### Pitfall 9: `tenancy:install` runs in a `composer post-install-cmd` script and races

**What goes wrong:**
Users add `tenancy:install` to their composer scripts (`post-install-cmd`, `post-update-cmd`) expecting auto-setup. Composer runs scripts in parallel for plugin-aware packages; the bundle's autoload may not be fully wired when our command tries to run. Worse, if the user's `composer.json` has multiple `post-install-cmd` entries, the order is not guaranteed in older Composer 2.x versions.

We don't have a Flex recipe (good — explicit non-goal), but we should also not implicitly become a composer plugin.

**How to avoid:**
- README explicitly states: `tenancy:install` is a **one-time setup** command. Do not add it to `composer scripts`. Run it once after `composer require`.
- If a user *does* add it to scripts, the command must be idempotent (already required from Pitfall 2) and must detect "already installed" cleanly (exit 0, message "already configured").
- Don't ship a composer plugin. The Symfony way is `tenancy:install`, called manually.

**Phase to address:** Phase DX-06 (documentation + idempotency tests).

---

### Pitfall 10: Profiler tab adds a `tenancy.collector` to public service IDs accidentally

**What goes wrong:**
The collector service must be private. If it's exposed as public (because of a missed `public: false`), the user's `dump($container->get('tenancy.collector'))` works in dev and breaks in prod (private services are removed from the production container after compile). This is the v0.2 issue #1 in spirit — config keys declared but with wrong scope/lifecycle.

**How to avoid:**
- All collector services have `public: false` in service config.
- Compiler pass assertion: every service in the `Tenancy\` namespace tagged `data_collector` is private.
- Test: `bin/console debug:container --show-hidden tenancy.collector` finds it; `bin/console debug:container tenancy.collector` (without flag) does not find it.

**Phase to address:** Phase DX-02.

---

### Pitfall 11: Demo app re-publishes the bundle's test fixtures

**What goes wrong:**
The demo (`examples/demo-app/`) needs fixture data — two tenants, sample entities. If the demo imports fixtures from the bundle's `tests/Fixtures/` directory (via path autoloading), the bundle is shipping test fixtures as public API by accident. The first user who tries to "follow the demo's pattern" will copy `use Tenancy\Tests\Fixtures\Tenant` and lock themselves to a test-internal class.

**How to avoid:**
- Demo has its own fixtures in `examples/demo-app/src/Entity/`. Zero imports from `Tenancy\Tests\*`.
- README explicit: demo entities are demo-specific; users define their own `Tenant` entity implementing `TenantInterface`.
- Add lint: grep `examples/` for `use Tenancy\Tests\` and fail CI.

**Phase to address:** Phase DEMO-01.

---

### Pitfall 12: Profiler tab queries the tenant provider on every dev request

**What goes wrong:**
The collector wants to show "active tenant: tenant-A (of 12 tenants)" — including total tenant count. Implementer calls `$tenantProvider->findAll()` in `collect()`. Every dev request now does a full `SELECT * FROM tenants`. On a project with 1000 tenants, dev becomes slow; on a project with sensitive tenant metadata, the dev profiler exposes data the dev shouldn't be reading.

**How to avoid:**
- Collector reads ONLY what's already in memory: the active tenant's slug/ID, the resolution metadata. No queries.
- If "total tenants" is wanted, expose it as a separate, lazily-rendered profile page (not in WDT or collect time) that explicitly hits the provider when the user clicks "Tenant Statistics."

**Phase to address:** Phase DX-02.

---

## Technical Debt Patterns

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|----------|-------------------|----------------|-----------------|
| `tenancy:install` mutates `bundles.php` with regex instead of php-parser | No `nikic/php-parser` dependency | Breaks on every non-standard bundles.php layout; user-file corruption incidents | **Never** — file mutation must be AST-based |
| Mailer bootstrapper overrides transport at `TenantResolved` and calls `send()` work fine in sync mode | Sync mailer tests pass | Async transport silently delivers from wrong tenant; tag-retraction event | **Never** — async test is non-optional |
| Profiler collector stores Tenant entity objects directly in `$this->data` | One-liner implementation | Profile pages broken on reload; serialization errors in logs | **Never** — store scalars only |
| Demo app uses Chrome-only `*.localhost` resolution without `/etc/hosts` fallback | Looks clean in demo URL | Firefox/Safari users hit DNS error on first run; demo "doesn't work" issues | **Never** — always provide `Host:` header fallback |
| OriginHeaderResolver does `str_contains($origin, $tenantOrigin)` | Slightly more permissive matching | Subdomain spoof: `attacker.tenant.example.com` matches `tenant.example.com` | **Never** — exact-equality or parsed-URL match only |
| Skip plan-summary parity check because "I'll write the summaries at close" | Faster phase execution | Retroactive summary authoring at milestone close (v0.2: 4 instances, ~15min each) | **Never after v0.2** — carry-forward item is mandatory |
| Demo app omits CI smoke job because "manual testing is enough" | One less CI job to maintain | Demo silently rots; users hit broken demo as first impression | **Never** — demo smoke is release-gate |
| `human_needed` VERIFICATION items left open past phase close | Phase can be marked done without external testing | Items accumulate across phases (v0.2: 3 unresolved at milestone close, 7-42 days latent) | **Never** — 72-hour TTL is the carry-forward rule |
| Mailer DSN logged as-is in exception traces | One less line of sanitization code | Credentials in error tracker, customer security incident | **Never** — DSN sanitization is mandatory |
| Hardcode `~/.composer-cache` mount in demo docker-compose | Faster composer install in demo | Per-user permission failures; "works on my Mac, not on Windows" | Only with named volume + documented opt-in |

---

## Integration Gotchas

| Integration | Common Mistake | Correct Approach |
|-------------|----------------|------------------|
| `symfony/mailer` + `symfony/messenger` async | Override transport at `TenantResolved` event in HTTP request → wrong transport used at send-time in worker | Use `X-Transport` header at dispatch OR decorate `Transports` selector with `TenantContext`-reading service (Pitfall 1, Approach 1 or 2) |
| `symfony/mailer` + `MessageEvent` | Mutate transport via `MessageEvent::getEnvelope()` and expect it to change which transport sends | `MessageEvent` cannot change the transport — only the envelope's sender/recipients. Use `X-Transport` header for transport selection |
| `WebProfilerBundle` + custom collector | Inject `EntityManager` and run queries in `collect()` | Snapshot scalar data from `TenantContext` only. No queries. No services that aren't already constructed |
| `nikic/php-parser` for `bundles.php` | Re-print AST and overwrite without checking for syntax errors | After re-print, `php -l` the result; on parse failure, restore from `.bak` |
| Docker Compose v2 + healthcheck dependencies | `depends_on:` without `condition: service_healthy` | Always pair `depends_on` with `condition: service_healthy` for DB-dependent services |
| Composer path repository + bundle development | Demo's `composer install --prefer-dist` copies bundle into vendor/, masking local changes | Default to `--prefer-source` in demo `composer install` command; document the gotcha |
| `*.localhost` browser resolution | Assume all browsers resolve subdomain of `.localhost` | Provide `Host:` header fallback; document Firefox/Safari behavior; CI smoke uses `Host:` not DNS |
| `OPTIONS` preflight + tenant resolution | Resolver throws on preflight because no tenant context | Resolver returns `null` on preflight; orchestrator's null-path (post-FIX-02) handles it |
| Symfony Messenger `TenantStamp` + Mailer's `SendEmailMessage` | Assume the existing Phase 06 middleware handles Mailer-dispatched messages | It does — but verify with a canary test specifically for `SendEmailMessage`, since it's dispatched through a different bus path than user messages |
| `WebProfilerBundle` template auto-discovery | Place template in wrong directory, no panel shows | Template must be at `src/Resources/views/Collector/tenancy.html.twig` (bundle root, not in subfolder) |

---

## Performance Traps

| Trap | Symptoms | Prevention | When It Breaks |
|------|----------|------------|----------------|
| Per-tenant SMTP transport rebuilt on every email send | Slow email sends (full TCP+TLS handshake per email); SMTP connection exhaustion on receiving server | LRU cache of transports in decorator; clear on tenant change | At 10+ emails/second per tenant |
| Profiler collector iterates over all tenants to display count | Dev page slow; tenant table read on every request | Collector reads only active-tenant snapshot; no provider queries | At 100+ tenants in dev environment |
| `tenancy:install` re-parses `bundles.php` on every dry-run | Fast enough — not a real trap | (No action needed — command is one-shot) | N/A |
| Demo app docker-compose without resource limits | Demo eats all of laptop's RAM; user blames bundle | `mem_limit:` and `cpus:` in docker-compose for each service | First-run laptop with 8GB RAM |
| OriginHeaderResolver linear scan over allow-list | Slow on requests with 1000-tenant config | Index allow-list by hostname at build time; O(1) lookup | At 1000+ origins configured |

---

## Security Mistakes

| Mistake | Risk | Prevention |
|---------|------|------------|
| Trust `Origin` header for tenant identification without authentication layer | Server-to-server attacker resolves any tenant by setting the header from non-browser client | Document the trust model; require auth-layer cross-check; explicit warning in resolver docs |
| Substring match in OriginHeaderResolver allow-list (`endsWith` / `contains`) | Subdomain spoof: `attacker.tenant.example.com` matches `tenant.example.com` allow-list entry | Exact equality on parsed URL components (scheme + host + port); single left-most wildcard label only |
| Mailer DSN with credentials leaked in exception trace | Tenant SMTP credentials exposed to error tracker (Sentry, monolog, browser in dev) | Sanitize all DSN-shaped strings in exception messages; test with explicit auth-failure case |
| `tenancy:install` writes file without backup; corruption is unrecoverable | User's `bundles.php` destroyed by botched mutation; no rollback path | Always backup before mutation; restore on `php -l` failure; print backup path in command output |
| Profiler tab exposes tenant connection DSN (with password) in panel | Credentials in dev console; if profile is stored, in profile JSON; if shared, in screenshots | Collector stores connection *name* only, never DSN; sanitize all stored data |
| Demo app ships hardcoded weak passwords in docker-compose | Users deploy demo without changing creds → "I deployed the demo to a public IP and now my DB is exfiltrated" | docker-compose uses `MYSQL_RANDOM_ROOT_PASSWORD: yes` or required `.env` with no defaults; README screams about not deploying to public IPs |
| OriginHeaderResolver fires on CORS preflight without tenant; orchestrator throws | Preflight returns 500 instead of CORS headers; SPA's actual request never fires; perceived as auth bug | Preflight handling explicit: resolver returns null; orchestrator's null-path proceeds; CORS middleware (Nelmio or nginx) responds normally |
| `tenancy:install` overwrites `bundles.php` with our version (full file replace) | User's other registered bundles disappear; immediate breakage of any non-trivial project | Mutate via AST surgery, not file replace; only insert our one entry; preserve all other entries verbatim |

---

## UX Pitfalls

| Pitfall | User Impact | Better Approach |
|---------|-------------|-----------------|
| WDT icon hidden when no tenant resolved | User can't tell if bundle is installed correctly or if request was just landlord-routed | Always show icon; grey + tooltip "no tenant" for null-resolution case |
| `tenancy:install` fails silently when `bundles.php` is non-standard | User runs install, sees success message, but bundle isn't registered | Detect non-standard layout; print manual snippet; exit 0 with clear "manual step required" message |
| Demo README assumes Chrome | Firefox/Safari users hit "site not found"; first impression is broken | Three-step fallback ladder in README (curl with Host:, /etc/hosts, browser-native) |
| Profiler panel shows JSON-dumped tenant entity (raw) | Developer sees noisy data dump, can't find the key info (which tenant?) | Render with clear labels: "Active Tenant: <slug>", "Resolved by: HostResolver", "Connection: <name>" |
| Error message "Origin not allowed" without listing allowed origins (in dev) | Developer doesn't know how to fix the config | In dev profile only, list allowed origins; in prod, generic message |
| `tenancy:install` doesn't print "what next" | User runs command, sees "done", doesn't know to also run `tenancy:init` and edit config | Always print next steps: run `tenancy:init`, edit `config/packages/tenancy.yaml`, test with `tenancy:run` |
| Demo's two tenants have similar names (`tenant1`, `tenant2`) | User can't tell which tenant they're viewing in browser; demo confuses rather than illustrates | Distinct names with distinct content/branding: "Acme Corp" (blue theme) and "Globex Inc" (green theme) |

---

## "Looks Done But Isn't" Checklist

### Mailer Bootstrapper (BOOT-04)
- [ ] **Sync path works:** transport overridden on `TenantResolved`, email sent in same request — **verify async path also works** by routing `SendEmailMessage` to Messenger transport in test and asserting worker sends from correct transport
- [ ] **Transport selection:** `X-Transport` header strategy implemented — **verify the header survives the Messenger transport's serialization** (JSON/AMQP/Doctrine), not just in-memory test
- [ ] **DSN sanitization:** exception messages don't include credentials — **verify with an explicit auth-failure test**, not just code review
- [ ] **Worker cleanup:** decorator cache cleared on `TenantContextCleared` — **verify SMTP socket count is bounded** in a 50-message worker stress test
- [ ] **Compile guard:** `MailerTransportContractPass` rejects mailer-enabled + no-strategy config — **verify with a negative test** (broken config → container compile fails with clear message)

### `tenancy:install` Command (DX-06)
- [ ] **Standard skeleton:** mutates `bundles.php` correctly — **verify on 6+ fixture types** (skeleton, API Platform, Sulu, DDD, project-with-comments, project-with-conditional-loads)
- [ ] **Idempotent:** running twice produces same result — **verify with a 3-run test** asserting file content identical after runs 2 and 3
- [ ] **Backup:** prints backup path — **verify backup file exists and is restorable** with a corruption-injection test
- [ ] **Atomic write:** uses `Filesystem::dumpFile()` — **verify via test that SIGTERM mid-write doesn't leave half-file**
- [ ] **Encoding preservation:** UTF-8 BOM, line endings — **verify with fixtures containing BOM and CRLF**
- [ ] **Non-standard refusal:** prints manual instructions, exit 0 — **verify on `Kernel::registerBundles()` override fixture**
- [ ] **Next-steps output:** command prints what to do next — **verify by string-matching the expected post-install snippet in output**

### Demo App (DEMO-01)
- [ ] **`docker compose up` works:** services start — **verify with CI smoke job hitting both tenant routes via `Host:` header**
- [ ] **Cross-browser:** README has Firefox/Safari fallback — **verify by reading README, not by testing every browser**
- [ ] **Path repo symlink:** local bundle changes reflect in demo — **verify by `readlink vendor/danplaton4/tenancy-bundle` in CI**
- [ ] **No test-fixture leakage:** demo doesn't import from `Tenancy\Tests\` — **verify with CI grep lint**
- [ ] **Distinct tenants:** visually different — **verify by snapshot test of `/` rendered for each tenant**
- [ ] **No hardcoded prod-unsafe creds:** weak passwords flagged — **verify README has "do not deploy this demo to public IP" warning**

### Profiler Tab (DX-02)
- [ ] **Panel renders with tenant:** active panel shows correct data — **verify with WebTestCase asserting `tenant_id` text on `/_profiler/<token>`**
- [ ] **Panel renders without tenant:** null-resolution case — **verify with separate WebTestCase on a landlord route**
- [ ] **Stored profile renders:** reload `/_profiler/<token>` after request — **verify the panel shows same data, not blank** (this is the serialization canary)
- [ ] **No queries in collect:** profiler doesn't add DB load — **verify with `assertCount(0, ...)` on query log delta during collect**
- [ ] **No DSN exposure:** connection name only, not password — **verify by inspecting collector's `$this->data` for credentials substrings**
- [ ] **Production compile-out:** collector service not in prod container — **verify with `bin/console debug:container --env=prod`**
- [ ] **Reset works:** `$collector->reset()` clears state — **verify long-running dev server doesn't leak data across requests**

### OriginHeaderResolver (RESV-06)
- [ ] **Exact origin match:** no substring matching — **verify with `attacker.tenant.example.com` against `tenant.example.com` allow-list** (must NOT match)
- [ ] **Preflight handling:** OPTIONS doesn't throw — **verify with an OPTIONS request test asserting CORS headers returned**
- [ ] **Null origin handling:** `Origin: null` doesn't match — **verify with explicit null-origin request test**
- [ ] **Resolver priority documented:** order vs Host/Header resolver clear — **verify in resolver docblock and `tenancy.yaml` defaults**
- [ ] **Conflict warning:** Origin and X-Tenant-ID disagreeing logs warning — **verify with WebTestCase capturing log output**
- [ ] **Security docs:** trust model section exists — **verify README has "When NOT to use OriginHeaderResolver" section**
- [ ] **Compile-time allow-list validation:** empty allow-list with resolver enabled fails compile — **verify with negative test on container build**

### Process / Governance (Carry-Forward from v0.2)
- [ ] **Plan-summary parity:** every plan has a SUMMARY.md — **verify with `audit-open` enforcement (not advisory)**
- [ ] **`human_needed` TTL:** no item older than 72h at any time — **verify with daily check, not just at milestone close**
- [ ] **Demo CI gate:** smoke job required for merge — **verify by attempting a merge with a broken demo and confirming it's blocked**
- [ ] **Release checklist:** pre-tag checklist exists and is enforced — **verify v0.3.0 tag is preceded by a documented checklist run**

---

## Recovery Strategies

| Pitfall | Recovery Cost | Recovery Steps |
|---------|---------------|----------------|
| Mailer async sends from wrong tenant in production | CRITICAL (customer-visible incident, possible DKIM/SPF reputation impact) | (1) Immediately disable async mailer routing globally — fallback to sync; (2) audit sent-mail logs for cross-tenant From: vs tenant slug mismatches; (3) notify affected tenants if any; (4) ship `X-Transport` header strategy patch; (5) post-mortem |
| `tenancy:install` corrupts user `bundles.php` | HIGH (user adoption loss, support burden) | (1) User restores from `.bak.<timestamp>` (printed in command output); (2) we ship a hotfix improving fixture coverage; (3) blog post + CHANGELOG with explicit "if you saw this, here's how" instructions |
| Demo CI smoke job is red on release commit | MEDIUM (would have shipped broken demo) | (1) Block release tag; (2) reproduce locally; (3) fix demo or document the regression; (4) re-tag only after green |
| Profiler tab shows blank for resolved tenants | LOW (no functional impact, dev confusion only) | (1) Verify with stored-profile reload test (the canary); (2) if reproducible, move from `lateCollect` to `collect` or fix priority; (3) ship patch |
| OriginHeaderResolver allows substring spoof in production | CRITICAL (cross-tenant access bypass) | (1) Treat as security incident; (2) audit access logs for unexpected Origin patterns matching tenants; (3) rotate affected tenant credentials if any auth-cross-check was missing; (4) ship strict-equality patch; (5) security advisory |
| `human_needed` items accumulate, milestone closes with unverified claims | MEDIUM (risk-shifting to users, retraction risk if defect found post-tag) | (1) Audit all open VERIFICATION items; (2) treat as Known Gaps in CHANGELOG; (3) escalate to 72h TTL going forward; (4) post-mortem in retrospective |
| Plan-summary drift caught at milestone close | LOW (governance debt, ~15min/plan to backfill) | (1) Retroactive SUMMARY with `retroactive: true` frontmatter; (2) add `audit-open` parity check before next milestone |

---

## Pitfall-to-Phase Mapping

| Pitfall | Prevention Phase | Compile/Lint Guard | Test Verification |
|---------|------------------|-------------------|--------------------|
| 1. Mailer async wrong transport | BOOT-04 | `MailerTransportContractPass` (compile-time) | Canary: dispatch in tenant A async, assert worker SMTP matches tenant A |
| 2. `tenancy:install` corrupts user file | DX-06 | `php -l` post-mutation (runtime guard) | 6+ fixture corpus test; 3-run idempotency test |
| 3. Profiler serialization / cleared-context blank | DX-02 | (Optional) `ProfilerCollectorContractPass` | Stored-profile reload test; null-resolution panel test |
| 4. Demo "works on my machine" | DEMO-01 | CI smoke job (workflow guard) | `bin/smoke.sh` running on Linux runner with curl `Host:` header |
| 5. OriginHeaderResolver security | RESV-06 | `OriginHeaderResolverConfigPass` (compile-time) | Substring-spoof test; preflight test; conflict-warning test |
| 6. Plan-summary drift / `human_needed` accumulation | Phase 0 (kickoff) | `audit-open` enforcement; 72h TTL | Retrospective carry-forward items closed |
| 7. Mailer DSN credential leak | BOOT-04 | (None) | Auth-failure exception test asserts no credentials in message |
| 8. Mailer transport cache OOM | BOOT-04 | (None) | 50-message worker stress test, memory bounded |
| 9. `tenancy:install` composer-script race | DX-06 (docs + tests) | (None) | Idempotency test (already covered for Pitfall 2) |
| 10. Profiler collector public service ID | DX-02 | Service-config `public: false` + container debug check | `bin/console debug:container tenancy.collector` is hidden |
| 11. Demo imports test fixtures | DEMO-01 | CI grep lint `examples/` → no `Tenancy\Tests\` | Lint job in `demo-smoke` CI |
| 12. Profiler queries on collect | DX-02 | (None) | Query-count delta = 0 during collect |

---

## Compile-Time Guards Proposed for v0.3 (Issue #5 Lesson Codified)

Three new compiler passes, following the `CacheDecoratorContractPass` pattern from v0.2 Phase 15:

### 1. `MailerTransportContractPass` (NEW — for BOOT-04)
- **Location:** `src/DependencyInjection/Compiler/MailerTransportContractPass.php`
- **Invariant:** If `tenancy.mailer.enabled = true` AND `interface_exists(\Symfony\Component\Mailer\MailerInterface::class)`, then **either** a service `tenancy.mailer.transport_provider` implementing `TenantTransportProviderInterface` is registered, **OR** the user has configured a `tenancy.mailer.transport_header_strategy: x_transport` option AND has at least one `framework.mailer.transports.tenant_*` entry.
- **Failure message:** "Mailer bootstrapper is enabled but no transport-resolution strategy is configured. Either implement `TenantTransportProviderInterface` for dynamic DSN resolution, OR define per-tenant transports in `framework.mailer.transports` and enable `x_transport` strategy. See https://...docs/mailer.md"
- **Async detection:** if `messenger.routing` contains `Symfony\Component\Mailer\Messenger\SendEmailMessage`, additionally require the `x_transport` strategy (the only async-safe option) or fail compile with explicit async-incompatibility message.

### 2. `OriginHeaderResolverConfigPass` (NEW — for RESV-06)
- **Location:** `src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php`
- **Invariant:** If `OriginHeaderResolver` is registered, then `tenancy.resolvers.origin_header.allow_list` is non-empty AND every entry parses as an absolute URL with valid scheme (`http`/`https`) and (optionally) one left-most wildcard label.
- **Failure message:** Per-entry: "`tenancy.resolvers.origin_header.allow_list[<index>]` is invalid: `<value>`. Must be an absolute URL like `https://tenant-a.example.com` or `https://*.example.com`."

### 3. `ProfilerCollectorContractPass` (OPTIONAL — for DX-02)
- **Location:** `src/DependencyInjection/Compiler/ProfilerCollectorContractPass.php`
- **Invariant:** If `WebProfilerBundle` is registered, the `tenancy.profiler.collector` service has the `data_collector` tag with the expected template path AND is `public: false`.
- **Skip if:** Adding this pass costs more than 10 lines — the failure mode (no panel) is benign, not security-shaped. Listed for completeness; the team should weigh value vs maintenance.

Pattern reminder, from `CacheDecoratorContractPass`: each pass converts a class of "silent at boot, explodes at consumption" bug into a deterministic container-compile error with a descriptive message linking to docs. The v0.2 retro called this out as the **single highest-leverage prevention pattern** the bundle has — apply it everywhere a downstream-only failure mode is statically detectable.

---

## Adoption-Specific Risk Register (v0.3-Only)

These risks aren't pitfalls per se — they're milestone-level risks specific to "first users will see this":

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| First user's `tenancy:install` corrupts their `bundles.php` | MEDIUM if mutation is via regex; LOW if via php-parser + backup + dry-run | HIGH (tag-retraction equivalent) | Pitfall 2 mitigations; fixture corpus test; dry-run mode; backup; refuse-on-nonstandard |
| First user opens demo in Firefox, sees DNS error, gives up | HIGH on Firefox/Safari users | MEDIUM (one-time adoption loss; recoverable if they file an issue) | Pitfall 4 mitigations; three-step fallback ladder in README; CI smoke catches the bundle-side breakages |
| First user's profiler panel is blank, assumes bundle is broken | MEDIUM | MEDIUM (perceived defect; possible bug report) | Pitfall 3 mitigations; always-show icon; stored-profile reload test |
| First user's tenant-A email goes from landlord SMTP | HIGH on async-routed projects | CRITICAL (customer-visible) | Pitfall 1 mitigations; compile-time guard; async canary test; recommend `X-Transport` |
| First user trusts `Origin` header for an unauthenticated API | MEDIUM | HIGH (cross-tenant leak) | Pitfall 5 mitigations; docs explicitly state trust model; warn at boot when allow-list is empty |
| v0.3 ships with `human_needed` items unresolved (v0.2 pattern recurs) | MEDIUM (without mitigation), LOW (with carry-forward 72h TTL enforced) | MEDIUM (governance debt; risk that "verification" item is actually a defect) | Pitfall 6 mitigations; Phase 0 process work; release-gate checklist |
| Demo CI rot — works at release, breaks 3 months later | HIGH without CI gate, LOW with | MEDIUM (re-establishes "doesn't work on my machine" as default perception) | CI smoke job is required for `master` merge, not just for releases |
| `tenancy:install` adopted in composer scripts, races | LOW | LOW (idempotency makes it safe even if it races) | README explicit "one-time only"; idempotency tests |

---

## Sources

### Verified (HIGH confidence)
- [Symfony Profiler — Custom Data Collectors](https://symfony.com/doc/current/profiler/data_collector.html) — official, `LateDataCollectorInterface` + serialization semantics
- [Symfony Mailer Docs](https://symfony.com/doc/current/mailer.html) — official, transport selection + async dispatch model
- [Symfony Messenger Docs](https://symfony.com/doc/current/messenger.html) — official, worker semantics
- [Symfony Mailer — Multiple Transports Discussion](https://github.com/symfony/symfony/discussions/46372) — `X-Transport` header selection mechanism
- [`symfony/symfony` issue #34972 — Redundant MessageEvent dispatch](https://github.com/symfony/symfony/issues/34972) — `RawMessage` clone semantics on `MessageEvent`
- [`symfony/symfony` issue #37588 — Allow setting Transport from MessageEvent](https://github.com/symfony/symfony/issues/37588) — confirms transport cannot be changed from `MessageEvent`
- [Symfony 6.2 More Extensible Mailer](https://symfony.com/blog/new-in-symfony-6-2-more-extensible-mailer) — `X-Bus-Transport` and bus selection
- [Symfony Bundle Best Practices](https://symfony.com/doc/current/bundles/best_practices.html) — official, service-naming and config conventions
- [Mozilla bug 1741109 — Firefox `*.localhost`](https://bugzilla.mozilla.org/show_bug.cgi?id=1741109) — Firefox subdomain-of-localhost handling
- [Mozilla bug 1686759 — Firefox `*.localhost` regression](https://bugzilla.mozilla.org/show_bug.cgi?id=1686759) — Firefox vs Chrome divergence
- [WebKit bug 160504 — Safari `*.localhost`](https://bugs.webkit.org/show_bug.cgi?id=160504) — Safari subdomain handling
- [OWASP — Testing CORS](https://owasp.org/www-project-web-security-testing-guide/latest/4-Web_Application_Security_Testing/11-Client-side_Testing/07-Testing_Cross_Origin_Resource_Sharing) — Origin trust model
- [PortSwigger — CORS Misconfigurations](https://portswigger.net/web-security/cors/access-control-allow-origin) — substring-match attack vectors

### Project-internal (HIGH confidence)
- `.planning/PROJECT.md` — v0.3 scope and key decisions
- `.planning/RETROSPECTIVE.md` — v0.2 governance lessons (plan-summary drift, `human_needed` TTL)
- `.planning/milestones/v0.2-research/PITFALLS.md` — pre-existing pitfalls still in force
- `.planning/v1.0-MILESTONE-AUDIT.md` — gap categories established in v1.0 audit
- `src/DependencyInjection/Compiler/CacheDecoratorContractPass.php` — compile-time guard pattern to replicate

### Community / Background (MEDIUM confidence)
- [GitHub discussion #61506 — Mailer DSN from database at runtime](https://github.com/symfony/symfony/discussions/61506) — community patterns for dynamic-DSN mailer
- [HackTricks — CORS Misconfigurations & Bypass](https://book.hacktricks.xyz/pentesting-web/cors-bypass) — Origin spoof from non-browser clients
- [Vercel Labs portless](https://github.com/vercel-labs/portless) — local subdomain routing precedent

---

*Pitfalls research for: Symfony tenancy bundle v0.3 Adoption Surface (new-feature failure modes)*
*Researched: 2026-05-15*
*Scope: ONLY v0.3 features and adoption-milestone risks; v0.2 pitfalls remain authoritative for shipped features*
