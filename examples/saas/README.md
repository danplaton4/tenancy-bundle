# Tenancy Bundle Demo — `examples/saas/`

A runnable two-+-landlord Symfony app demonstrating the `danplaton4/tenancy-bundle` end-to-end. FrankenPHP + Caddy + MariaDB 11 + Mailpit, single `docker compose up`, three tenants (acme, globex, initech) on the same code path with isolated data.

## Two-minute boot

```bash
cd examples/saas
docker compose up -d --wait --build
open http://acme.tenancy.localhost/
```

On a warm image cache the stack boots in ~50s; cold (first image pull) ~110s. `--wait` returns only when every healthcheck has passed (db: innodb initialized, mailpit: ready, php: `/health` 200 — which only goes green after `app:seed-demo` completes).

Once up:
- `http://tenancy.localhost/` — landlord page (lists the three tenants)
- `http://acme.tenancy.localhost/` — Acme tenant landing
- `http://globex.tenancy.localhost/` — Globex tenant landing
- `http://initech.tenancy.localhost/` — Initech tenant landing

Each tenant page shows the tenant's brand name, accent color, and 2-3 seeded posts — distinct per tenant.

## Three-step fallback

`*.tenancy.localhost` is RFC 6761 §6.3 magic — most modern browsers resolve it to 127.0.0.1 without DNS. If yours doesn't, walk the ladder:

### Step 1 — curl with `Host:` header (works everywhere)

```bash
curl -fsS -H "Host: acme.tenancy.localhost" http://localhost/
# Body should contain "Acme Corporation"
```

This is exactly what `bin/smoke.sh` does, and what CI runs. DNS-independent.

### Step 2 — `/etc/hosts` (Safari + WSL2 fallback)

Add to `/etc/hosts` (macOS/Linux) or `C:\Windows\System32\drivers\etc\hosts` (Windows / WSL2 — note: edit the **Windows-host** hosts file, not the WSL2 distro's):

```
127.0.0.1 tenancy.localhost acme.tenancy.localhost globex.tenancy.localhost initech.tenancy.localhost
```

### Step 3 — Browser-native `*.tenancy.localhost`

Out of the box on Chrome/Chromium (all OS), Firefox 84+ (since 2020), Microsoft Edge. Open `http://acme.tenancy.localhost/` and it just works.

| OS | Browser | Works out of the box? |
|---|---|---|
| macOS | Chrome / Chromium / Edge | Yes |
| macOS | Safari | No — use Step 2 |
| macOS | Firefox 84+ | Yes |
| Linux | Chrome / Firefox | Yes |
| Windows | Chrome / Edge | Yes |
| Windows + WSL2 | Chrome on Windows | usually Yes; if not, use Step 2 (Windows-host hosts file) |

## The Profiler walkthrough (Phase 19)

The demo runs `APP_ENV=dev`, so the Symfony Web Debug Toolbar (WDT) is on by default.

1. Open `http://acme.tenancy.localhost/` in Chrome.
2. At the bottom of the page, click the **Tenancy** icon in the WDT.
3. Observe: `slug: acme`, `tenant_label: Acme Corporation`, `driver: database_per_tenant`, `connection_name: tenant`, `resolved_by: Tenancy\Bundle\Resolver\HostResolver`, and the list of bootstrappers that ran.
4. Visit `http://tenancy.localhost/` (the landlord). The Tenancy panel still renders, but in its "no tenant" state — confirming null-resolution is a first-class state, not an error.

## The Mailer walkthrough (Phase 20)

Mailpit is always up. Mailpit UI lives at `http://localhost:8025/`.

Send a test email FROM each tenant (the ONLY write path in this demo):

```bash
curl -X POST -H "Host: acme.tenancy.localhost" http://localhost/_demo/send-test-mail
curl -X POST -H "Host: globex.tenancy.localhost" http://localhost/_demo/send-test-mail
curl -X POST -H "Host: initech.tenancy.localhost" http://localhost/_demo/send-test-mail
```

Open `http://localhost:8025/`. Three messages appear, with distinct `From:` and `Reply-To:` addresses (`noreply@acme.example`, `noreply@globex.example`, `noreply@initech.example`). The controller code is the same for every tenant; the bundle's `MailerBootstrapper` + `TenantMessageDecorator` inject the correct From/Reply-To per resolved tenant.

## The OriginHeaderResolver scenario (Phase 17)

For SPAs that send `Origin` but not `X-Tenant-ID`, the bundle resolves the tenant from `Origin`:

```bash
curl -fsS \
     -H "Host: tenancy.localhost" \
     -H "Origin: https://acme.tenancy.localhost" \
     http://localhost/
# Body contains "Acme Corporation" — resolved via Origin chain (priority 25)
```

The Host header points at the landlord (no leftmost-label slug); OriginHeaderResolver fires next in the chain and picks up the cross-origin Origin against the allow-list configured in `config/packages/tenancy.yaml`. See the bundle docs for `Origin`'s trust model — browser-protected for cross-origin XHR, but spoofable from non-browser clients.

## Optional: HTTPS with Caddy's local CA

The default walkthrough uses HTTP — zero cert friction. To enable HTTPS:

1. Trust Caddy's internal CA on your host:
   ```bash
   docker compose exec php caddy trust
   ```
   This installs the CA into the system trust store (Keychain on macOS, `/usr/local/share/ca-certificates/` on Linux). You will be prompted for sudo.

2. **Firefox** uses its own trust store. After `caddy trust`, also manually import the CA into Firefox:
   - Open `about:preferences#privacy` in Firefox
   - Click **View Certificates** then **Authorities** then **Import**
   - Get the CA cert: `docker compose exec php cat /data/caddy/pki/authorities/local/root.crt` (save output to a temp file and import that file)

3. Visit `https://acme.tenancy.localhost/` — cert is wildcard, covers all three tenants.

## What if I want to install Tenancy Bundle in MY app?

Two commands (replace `my-app/` with your Symfony app dir):

```bash
cd my-app
composer require danplaton4/tenancy-bundle
bin/console tenancy:install
```

`tenancy:install` registers `TenancyBundle::class` in your `config/bundles.php` and runs `tenancy:init` to scaffold `config/packages/tenancy.yaml`. See the [main bundle README](../../README.md) for full docs.

> The demo's own `config/bundles.php` is hand-committed — we don't run `tenancy:install` during demo boot because path-repo'd setups need a few extra wiring bits (Doctrine two-EM split etc.) that the standard installer doesn't generate.

## Bundle-source dev loop

Path-repo with `symlink: true` + bind-mount: edits to `../../src/**` (the bundle's source) reflect inside the container immediately.

```bash
# Edit a bundle file, e.g. add error_log() in src/Resolver/HostResolver.php
docker compose restart php   # OPcache picks up the change (validate_timestamps=1, revalidate_freq=0)
curl -H "Host: acme.tenancy.localhost" http://localhost/
docker compose logs php | tail
```

No image rebuild required. The compose.yaml bind-mounts `../../src` into `/srv/src` in the container.

## Smoke + CI

`bin/smoke.sh` runs host-side curl assertions:

```bash
cd examples/saas
docker compose up -d --wait --build
bash bin/smoke.sh
# Asserts landlord lists 3 tenants, each tenant page has its body marker,
# and the Origin-resolver path resolves correctly.
docker compose down -v
```

The same script runs on every push to `master` and on every PR via `.github/workflows/demo-smoke.yml`. Failure blocks merge.

## Security notes

- This is a **local-dev demo**, not a production blueprint.
- `POST /_demo/send-test-mail` accepts unauthenticated requests — deliberate; localhost-only.
- MariaDB credentials in `.env` are committed defaults (`root`/`tenancy`); fine for localhost; **never reuse for production**.
- Mailpit UI binds to `127.0.0.1:8025` only — not exposed beyond loopback.
- `Host:` header injection by `bin/smoke.sh` is intended — tenant resolution by host is the bundle's job; production-shaped authn is YOUR app's job (see the bundle's docs).

## File layout

```
examples/saas/
├── bin/smoke.sh                 # host-side curl smoke; what CI runs
├── compose.yaml                 # FrankenPHP + MariaDB + Mailpit
├── Dockerfile                   # FrankenPHP base + composer install at build time
├── Caddyfile                    # *.tenancy.localhost + tls internal
├── docker/{entrypoint.sh,php.ini}
├── composer.json                # path-repo to ../../ (the bundle)
├── config/{bundles.php,packages/*.yaml,routes.yaml}
├── src/
│   ├── Command/SeedDemoCommand.php       # CREATE DATABASE + schema + posts (idempotent)
│   ├── Controller/{Landlord,Tenant,DemoMail,Health}Controller.php
│   ├── DataFixtures/LandlordTenantsFixture.php
│   ├── Entity/Landlord/DemoTenant.php    # adds brandColor to bundle's Tenant
│   ├── Entity/Tenant/Post.php
│   └── Repository/PostRepository.php
├── templates/{base,landlord/index,tenant/index}.html.twig
└── README.md                    # you are here
```
