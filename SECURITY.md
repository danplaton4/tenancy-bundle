# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 0.1.x   | Yes       |

## Reporting a Vulnerability

**Do not open a public GitHub issue for security vulnerabilities.**

This is a multi-tenancy bundle. A vulnerability here could mean data leaking between tenants — the most serious class of bug in a multi-tenant system.

### How to report

Email **security@danplaton.com** with:

- Description of the vulnerability
- Steps to reproduce
- Which isolation driver is affected (`database_per_tenant`, `shared_db`, or both)
- Impact assessment (data leak, privilege escalation, denial of service)

### What to expect

- **Acknowledgment** within 48 hours
- **Assessment** within 1 week
- **Fix or mitigation** within 2 weeks for critical issues
- **Credit** in the release notes (unless you prefer anonymity)

### Scope

The following are in scope:

- Cross-tenant data leaks (queries returning wrong tenant's data)
- Tenant context pollution (previous tenant's state leaking to next request)
- Cache namespace collisions between tenants
- Messenger context not properly restored or torn down
- SQL filter bypass in strict mode
- Authentication/authorization bypass through tenant switching

### Out of scope

- Vulnerabilities in Symfony, Doctrine, or other dependencies (report upstream)
- Configuration mistakes in the consuming application
- Issues requiring physical access to the server