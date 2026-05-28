# SaaS Demo (runnable)

A runnable Symfony 7.4 app under `examples/saas/` that boots three tenants — `acme`,
`globex`, `initech` — on a single `docker compose up`. FrankenPHP + Caddy +
MariaDB 11 + Mailpit, all wired together so `http://acme.tenancy.localhost/` shows the
Acme tenant page and `http://globex.tenancy.localhost/` shows a different one.

The demo is the bundle's living integration test: `bin/smoke.sh` runs against it on every
push and PR via `.github/workflows/demo-smoke.yml`, so a green CI badge means the demo
actually boots and resolves tenants correctly on a clean machine.

## What this demo proves

- **Subdomain resolver works out of the box** — `*.tenancy.localhost` is RFC 6761 magic;
  modern browsers route it to 127.0.0.1 with no DNS or `/etc/hosts` setup.
- **The Profiler tab shows the resolved tenant per request** — open the WDT on any tenant
  page and the [Tenancy panel](../user-guide/profiler-tab.md) renders slug, driver,
  bootstrappers, and resolver FQCN.
- **Per-tenant SMTP via the Mailer bootstrapper** — three `POST /_demo/send-test-mail`
  calls land in Mailpit with distinct `From:` and `Reply-To:` per tenant.
- **OriginHeaderResolver fires when the Host doesn't carry a slug** — a cross-origin
  request to the landlord with `Origin: https://acme.tenancy.localhost` still resolves
  to Acme via the priority-25 resolver.
- **The three-step fallback ladder** — when `*.tenancy.localhost` doesn't resolve locally
  (Safari, some WSL2 setups), `curl -H "Host: ..."` and `/etc/hosts` cover everything.

## Quick start

```bash
git clone https://github.com/danplaton4/tenancy-bundle && \
  cd tenancy-bundle/examples/saas && \
  docker compose up -d --wait --build
```

On a warm Docker image cache the stack boots in ~50s; cold (first image pull) ~110s.
`--wait` returns only when every healthcheck has passed. If `*.tenancy.localhost`
doesn't resolve in your browser, the [demo README](../../examples/saas/README.md) walks
through the three-step fallback ladder (curl `Host:` header → `/etc/hosts` → browser-native).

## A peek at the demo

```
+--------------------------------+  +--------------------------------+
| http://acme.tenancy.localhost/ |  | http://globex.tenancy.localhost|
|--------------------------------|  |--------------------------------|
| Acme Corporation               |  | Globex Industries              |
| brand: #4a5cff                 |  | brand: #ff6a00                 |
| posts: 3 (acme-only data)      |  | posts: 2 (globex-only data)    |
+--------------------------------+  +--------------------------------+
WDT: slug=acme  driver=database_per_tenant  resolved_by=HostResolver
```

Same controller code serves both pages; the bundle's `HostResolver` picks the leftmost
DNS label, `DatabaseSwitchBootstrapper` swaps the DBAL socket, and Doctrine returns the
tenant's isolated row set.

## Full walkthrough

The canonical demo doc covers the two-minute boot, the three-step fallback ladder, the
Profiler walkthrough, the Mailpit walkthrough, the OriginHeaderResolver scenario, the
optional HTTPS-with-Caddy step, and the bundle-source dev loop. See
**[examples/saas/README.md](../../examples/saas/README.md)** for the full guide.

## See also

- [Installation](../user-guide/installation.md) — install the bundle in your own app
- [Profiler tab](../user-guide/profiler-tab.md) — Tenancy panel internals
- [Mailer bootstrapper](../user-guide/mailer-bootstrapper.md) — per-tenant SMTP DSN, From, Reply-To
- [Origin header resolver](../user-guide/origin-header-resolver.md) — Trust Model + allow-list
