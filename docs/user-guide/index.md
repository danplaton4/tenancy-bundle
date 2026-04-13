# User Guide

Everything you need to install, configure, and use Tenancy Bundle in your Symfony application.

## Getting Started

New to the bundle? Start here:

1. [Installation](installation.md) — composer require, Flex auto-config, manual registration
2. [Getting Started](getting-started.md) — 5-minute end-to-end walkthrough
3. [Configuration Reference](configuration.md) — every `tenancy.yaml` key explained

## Features

- [Resolvers](resolvers.md) — subdomain, header, query param, console, custom
- [Database-per-Tenant](database-per-tenant.md) — DBAL wrapperClass connection switching
- [Shared-DB Driver](shared-db.md) — SQL filter with `#[TenantAware]` attribute
- [Cache Isolation](cache-isolation.md) — per-tenant cache namespace
- [Messenger Integration](messenger.md) — TenantStamp, sending and worker middleware
- [CLI Commands](cli-commands.md) — tenancy:migrate, tenancy:run
- [Testing](testing.md) — InteractsWithTenancy PHPUnit trait
- [Strict Mode](strict-mode.md) — data leak prevention (default ON)

## Real-World Examples

- [SaaS Subdomain](examples/saas-subdomain.md) — full subdomain-based multi-tenant SaaS
- [API Header](examples/api-header.md) — X-Tenant-ID header for API-first apps
