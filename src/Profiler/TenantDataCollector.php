<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Profiler;

use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Mailer\DsnSanitizer;
use Tenancy\Bundle\Mailer\LruTransportCache;
use Tenancy\Bundle\TenantInterface;

/**
 * Web Profiler data collector for the Tenancy panel.
 *
 * Reads scalars synchronously on kernel.response via collect() (NOT the late variant — DX-02 acceptance line 2).
 * Stores an 8-key scalar-only array in $this->data so stored-profile reload round-trips losslessly.
 *
 * Three render states (D-04):
 *   - 'resolved': TenantContext->hasTenant() is true
 *   - 'error':    stash captured a Tenancy\Bundle\Exception\* exception
 *   - 'null':     no tenant resolved (public/landlord/health-check route) AND no error
 *
 * SECURITY (D-09): connection_name is a LABEL string ('tenant' or '%tenancy.landlord_connection%'),
 * NEVER a DSN. The match expression below produces only literal labels; the defensive `:`/`@` check
 * is a tripwire that throws if anyone ever wires a DSN-laden value through DI.
 *
 * MAILER SECTION (Phase 20, D-08): When the optional LruTransportCache dependency is wired (mailer
 * interface installed), an additional 'mailer' key is appended to $this->data with a 10-field
 * scalar-only sub-array. The DSN is redacted via DsnSanitizer::redact (single source of truth shared
 * with SanitizingMailerDecorator — Plan 02) so no raw password ever reaches the stored profile dump.
 * A defense-in-depth tripwire throws if the redacted DSN still looks credentialed (catches future
 * regex regressions in DsnSanitizer).
 */
final class TenantDataCollector extends AbstractDataCollector
{
    /**
     * @param string $driver             Value of %tenancy.driver% — 'database_per_tenant' | 'shared_db'
     * @param string $landlordConnection Value of %tenancy.landlord_connection% (defaults to 'default')
     */
    private const KNOWN_DRIVERS = ['database_per_tenant', 'shared_db'];

    public function __construct(
        private readonly TenantProfilerStash $stash,
        private readonly TenantContext $tenantContext,
        private readonly string $driver,
        private readonly string $landlordConnection,
        private readonly ?LruTransportCache $cache = null,
        private readonly ?string $mailerAsync = null,
    ) {
        if (!\in_array($driver, self::KNOWN_DRIVERS, true)) {
            throw new \InvalidArgumentException(sprintf('TenantDataCollector: $driver must be one of [%s], got "%s".', implode(', ', self::KNOWN_DRIVERS), $driver));
        }
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $tenant = $this->tenantContext->getTenant();

        if (null !== $tenant) {
            $state = 'resolved';
        } elseif (null !== $this->stash->getCapturedException()) {
            $state = 'error';
        } else {
            $state = 'null';
        }

        $connectionName = match ($this->driver) {
            'database_per_tenant' => 'tenant',
            'shared_db' => $this->landlordConnection,
            default => null,
        };

        // Defensive tripwire (D-09): connection_name is a label, never a DSN.
        if (null !== $connectionName && (str_contains($connectionName, ':') || str_contains($connectionName, '@'))) {
            throw new \RuntimeException(sprintf('TenantDataCollector: connection_name "%s" looks like a DSN — never display credentials.', $connectionName));
        }

        $this->data = [
            'state' => $state,
            'slug' => $tenant?->getSlug(),
            'tenant_label' => $tenant?->getName(),
            'driver' => $this->driver,
            'connection_name' => $connectionName,
            'resolved_by' => $this->stash->getResolvedBy(),
            'bootstrappers' => array_values(array_map('strval', $this->stash->getBootstrapperFqcns())),
            'error' => $this->stash->getCapturedException(),
        ];

        // Mailer subsection (Phase 20, D-08) — only when the optional cache dep is wired.
        // Twig template gates on `{% if collector.data.mailer is defined %}` so absence is graceful.
        if (null !== $this->cache) {
            $this->data['mailer'] = $this->collectMailerState($tenant);
        }
    }

    public function getName(): string
    {
        return 'tenancy';
    }

    public static function getTemplate(): string
    {
        return '@Tenancy/Collector/tenant.html.twig';
    }

    /**
     * Public accessor for $this->data — used by:
     *   - tests/Unit/Profiler/TenantDataCollectorTest (shape assertions)
     *   - tests/Integration/Profiler/TenantDataCollectorSerializationTest (round-trip equality)
     *   - Twig template indirectly via collector.data.* (works via __get on AbstractDataCollector,
     *     but explicit accessor aids tests and integration verification).
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        // AbstractDataCollector::$data is typed `array|Data`. We never wrap via VarDumper helpers (D-11),
        // so it is always a plain array here. The assertion documents the invariant.
        \assert(is_array($this->data), 'TenantDataCollector::$data must be a plain array — scalar-only invariant per D-11.');

        return $this->data;
    }

    /**
     * Build the mailer subsection of $this->data (Phase 20, D-08).
     *
     * Returns 10 scalar-only keys: from, reply_to, dsn_redacted, cache_size, cache_max,
     * cache_hits, cache_evictions, strategy, async_detected, badge. Every value is either
     * scalar (int|string|bool) or null — preserves the Phase 19 scalar-only invariant.
     *
     * Badge state:
     *   - 'OK'       — DSN present, OR no tenant resolved (nothing wrong, just inactive)
     *   - 'MISSING'  — tenant resolved AND has no mailerDsn (misconfig signal)
     *
     * DSN redaction is delegated to DsnSanitizer::redact — the single source of truth shared
     * with SanitizingMailerDecorator. Defense-in-depth: after redaction, the result is sniffed
     * for residual credential patterns; any match throws, so a regex regression in DsnSanitizer
     * cannot silently leak passwords into the stored profile dump.
     *
     * @return array{from: ?string, reply_to: ?string, dsn_redacted: ?string, cache_size: int, cache_max: int, cache_hits: int, cache_evictions: int, strategy: string, async_detected: ?string, badge: string}
     */
    private function collectMailerState(?TenantInterface $tenant): array
    {
        \assert(null !== $this->cache, 'collectMailerState must only be called when the LruTransportCache dependency is wired.');

        $dsn = $tenant?->getMailerDsn();
        $redacted = DsnSanitizer::redact($dsn);

        // Defense-in-depth tripwire (D-08 + Phase 20 threat T-20-07-01): if DsnSanitizer ever
        // regresses (e.g. the regex constant shifts), catch the leak HERE rather than letting
        // the password reach $this->data. Pattern: any `:<something-other-than-***>@` in the
        // redacted output proves the password wasn't replaced. Mirrors the connection_name
        // tripwire above (D-09).
        if (null !== $redacted && 1 === preg_match('/:(?!\/\/)(?!\*\*\*@)[^:@\/]+@/', $redacted)) {
            throw new \RuntimeException('TenantDataCollector: redacted DSN still appears to contain credentials — DsnSanitizer regex regression?');
        }

        $badge = (null === $tenant)
            ? 'OK'
            : (null === $dsn ? 'MISSING' : 'OK');

        return [
            'from' => $tenant?->getMailerFrom(),
            'reply_to' => $tenant?->getMailerReplyTo(),
            'dsn_redacted' => $redacted,
            'cache_size' => $this->cache->size(),
            'cache_max' => $this->cache->maxSize(),
            'cache_hits' => $this->cache->hits(),
            'cache_evictions' => $this->cache->evictions(),
            'strategy' => 'x_transport',
            'async_detected' => $this->mailerAsync,
            'badge' => $badge,
        ];
    }
}
