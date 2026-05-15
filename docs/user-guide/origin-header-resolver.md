# OriginHeaderResolver

SPA-friendly tenant resolver that reads the browser-set `Origin` HTTP header and matches it against a configurable allow-list. Sits in the resolver chain at priority **25** — above `HeaderResolver` (20), below `HostResolver` (30).

---

## Overview

When a single-page app at `https://acme.app.example.com` makes a cross-origin XHR/fetch call to your API at `https://api.example.com`, the browser stamps the request with `Origin: https://acme.app.example.com`. `OriginHeaderResolver` reads that header, looks the origin up in an allow-list configured under `tenancy.origin.allow_list`, and resolves the tenant — no extra header, no query param, no subdomain routing required.

The resolver is **opt-in**: it is not in the default `tenancy.resolvers` list. Enable it by adding `'origin'` to your resolver chain and configuring at least one allow-list entry.

Priority 25 means Origin wins over `X-Tenant-ID` (20) when both are set in the same request — see [Mismatch Warning](#mismatch-warning) below — and loses to subdomain-based `HostResolver` (30) when both are configured.

---

## Configuration

Add `'origin'` to your resolvers list and define the allow-list:

```yaml
# config/packages/tenancy.yaml
tenancy:
  resolvers: ['host', 'header', 'origin']
  origin:
    allow_list:
      # Explicit map form: pin a specific origin to a specific tenant slug.
      - { origin: 'https://acme.app.example.com', slug: 'acme' }
      - { origin: 'https://beta.app.example.com', slug: 'beta-customer' }

      # Wildcard shorthand: leftmost label becomes the slug at runtime.
      # `https://*.app.example.com` matches `https://anything.app.example.com`
      # and resolves to tenant slug = `anything`.
      - 'https://*.app.example.com'
```

### Allow-list entry rules

| Rule | Why |
|------|-----|
| Origin must be an absolute URL `scheme://host[:port]` | RFC 6454 — origins are bare authorities |
| Scheme must be `http` or `https` | Browsers only set `Origin` for these schemes |
| Port defaults to `80` for `http`, `443` for `https` when omitted | Normalized at compile time so runtime matching is exact-equality |
| Exactly one `*` allowed, in the **leftmost label only** | Mid-string wildcards (`app.*.example.com`) are silently permissive — rejected at compile time |
| No path, query, or fragment | Origins have no path component per RFC 6454 |
| Non-wildcard entries require an explicit `slug` | Wildcard entries derive slug from the matched label |

Invalid configurations fail at **container compile time** with a descriptive error naming the offending entry. There is no way to ship a misconfigured allow-list to runtime.

---

## Trust Model

> **Origin is a browser-locked routing hint, not an authentication credential. Pair this resolver with your real auth layer.**

### Where the `Origin` header comes from

For cross-origin XHR, `fetch()`, and CORS preflight requests, the browser sets `Origin` itself. JavaScript running in the page **cannot override** `Origin` — it is in the "forbidden header name" list of the Fetch standard. This makes `Origin` a strong tenant-routing signal **inside a browser context**: a tab origin-locked to `https://acme.app.example.com` will always send that exact `Origin` value, regardless of what the page's JavaScript wishes.

### Where the trust ends

`Origin` is **trivially settable from non-browser clients**:

- `curl -H 'Origin: https://acme.app.example.com' …` works
- Postman, Insomnia, HTTPie, native mobile (NSURLSession, OkHttp), server-to-server clients — all can forge `Origin` freely
- Bots and scrapers can spoof `Origin` to whatever value gets the response they want

Treat `OriginHeaderResolver` as a **routing convenience for browser SPAs**. Always pair it with your real authentication layer — Bearer tokens, cookies with CSRF protection, signed requests — for any endpoint that does anything sensitive. The resolver picks the tenant; your auth picks the user.

### Failure-safe by default

Two design choices make misconfiguration impossible-to-ship rather than dangerous-at-runtime:

1. **Opt-in** — Origin is not in the default resolvers list. Adding it is an explicit choice.
2. **Empty allow-list = compile error** — A YAML file with `'origin'` in resolvers but no `allow_list` entries fails at container build, not at runtime. There is no degenerate state where the resolver silently accepts every origin or silently rejects every origin.

### A note on `http://`

Both `http://` and `https://` are permitted in allow-list entries because local SPA dev servers (`http://localhost:3000`, `http://localhost:5173`) need to route to a dev tenant. **Mixing `http://` and `https://` origins in a production allow-list is a security smell** — production traffic should be HTTPS-only and your allow-list should reflect that.

---

## Mismatch Warning

When a request arrives with **both** a matching `Origin` header AND an `X-Tenant-ID` header whose slug differs from the Origin-resolved slug, `OriginHeaderResolver`:

1. Resolves the tenant from `Origin` (Origin wins because priority 25 > HeaderResolver's 20).
2. Emits a `warning`-level PSR-3 log record so operators can detect routing-confusion attempts.

The log payload is intentionally structured:

```json
{
  "level": "warning",
  "message": "Origin/X-Tenant-ID mismatch — Origin wins",
  "context": {
    "origin": "https://acme.app.example.com",
    "origin_slug": "acme",
    "header_slug": "beta",
    "winner": "origin"
  }
}
```

Wire this into your normal log pipeline; alert if the warning rate exceeds a baseline. Slug comparison is case-insensitive — `acme` and `ACME` are treated as the same tenant for the purposes of this check.

---

## Examples

### A React SPA at `app.example.com` calling an API at `api.example.com`

```yaml
tenancy:
  resolvers: ['origin']
  origin:
    allow_list:
      - 'https://*.app.example.com'  # any tenant subdomain
```

```javascript
// In the SPA — origin is set automatically by the browser
fetch('https://api.example.com/users', { credentials: 'include' })
```

### Two named tenants on the same multi-tenant SPA host

```yaml
tenancy:
  resolvers: ['origin', 'header']
  origin:
    allow_list:
      - { origin: 'https://acme.app.example.com', slug: 'acme' }
      - { origin: 'https://contoso.app.example.com', slug: 'contoso' }
```

### Local development with a Vite dev server

```yaml
tenancy:
  resolvers: ['origin']
  origin:
    allow_list:
      - { origin: 'http://localhost:5173', slug: 'dev-tenant' }
      - 'https://*.app.example.com'  # staging/prod still works
```

### CORS preflight requests

`OPTIONS` requests are passed through the resolver chain without any Origin parsing — the resolver returns `null` immediately so preflight never throws or routes to a tenant context. Browser preflight semantics are preserved. Setting `Access-Control-Allow-Origin` is your application's responsibility (typically via `nelmio/cors-bundle` or Symfony's built-in CORS handling).
