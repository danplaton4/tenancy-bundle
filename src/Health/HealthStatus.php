<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Health;

/**
 * Backed string enum representing the health status of a component or tenant.
 *
 * Values match the IETF application/health+json `status` field
 * (draft-inadarei-api-health-check): pass / warn / fail.
 *
 * Backed by string so the value serializes directly to the JSON response body
 * without additional mapping. PHPStan L9 benefits from exhaustiveness checking
 * on match expressions over enum cases.
 *
 * @see https://inadarei.github.io/rfc-healthcheck/ IETF health+json spec
 */
enum HealthStatus: string
{
    /** Component is fully operational. */
    case Pass = 'pass';

    /** Component is operational but degraded; worth monitoring. */
    case Warn = 'warn';

    /** Component is non-operational or unreachable. */
    case Fail = 'fail';
}
