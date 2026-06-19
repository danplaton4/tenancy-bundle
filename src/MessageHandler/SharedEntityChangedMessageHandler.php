<?php

declare(strict_types=1);

namespace Tenancy\Bundle\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Exception\SharedEntityAsyncFanOutException;
use Tenancy\Bundle\Message\SharedEntityChangedMessage;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\Shared\SharedEntityCopierInterface;
use Tenancy\Bundle\Shared\TenantEmSwitcherInterface;

/**
 * Async handler for SharedEntityChangedMessage — per-tenant convergence on the worker thread.
 *
 * ## Fan-out flow (__invoke)
 *
 * 1. Security gate: validate $message->entityClass against findSharedClasses() — an unknown
 *    class throws UnrecoverableExceptionInterface (no retry, T-27-02-CLASSINJ).
 * 2. Stale-read mitigation (Pitfall 3, T-27-02-STALE): clear($class) BEFORE find() so the
 *    re-fetch hits the DB in a long-running worker rather than returning an identity-map row.
 * 3. Re-fetch latest landlord state at handle time (D-05).
 * 4. Vanished-row→delete (D-04): if find() returns null on insert/update, propagate delete.
 * 5. Per-tenant fan-out: switchToTenant() + copier->deleteRow() (delete) or applyRow() (upsert).
 *    Failures are caught per-tenant (best-effort), logged, and accumulated in $failures.
 * 6. Throw-to-retry (D-02): after the loop, throw SharedEntityAsyncFanOutException if any
 *    tenant failed so Messenger's retry_strategy re-enqueues the message.
 *
 * ## No #[AsMessageHandler] — registered via explicit messenger.message_handler tag
 *
 * Bundle services do NOT use autoconfigure / #[AsMessageHandler]. Registration is done in
 * TenancyBundle::loadExtension() inside the interface_exists(MessageBusInterface) block.
 * (RESEARCH Pattern 1 / Pitfall 4 — FrameworkExtension processes #[AsMessageHandler] only
 * for autoconfigured services, which bundle services are not.)
 *
 * ## Tenant switching (W-02 de-dup)
 *
 * Tenant context switching is delegated to the injected TenantEmSwitcherInterface.
 * The duplicated switchToTenant()/restoreTenantContext() private methods from Plan 27-02
 * have been extracted into TenantEmSwitcher (single source of truth).
 *
 * @see TenantEmSwitcher
 */
final class SharedEntityChangedMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $landlordEm,
        private readonly TenantProviderInterface $tenantProvider,
        private readonly SharedEntityCopierInterface $copier,
        private readonly TenantContext $tenantContext,
        private readonly ManagerRegistry $registry,
        private readonly LoggerInterface $logger,
        private readonly TenantEmSwitcherInterface $switcher,
    ) {
    }

    public function __invoke(SharedEntityChangedMessage $message): void
    {
        $class = $message->entityClass;
        $identifier = $message->identifier;
        $changeType = $message->changeType;

        // ---- Security gate (T-27-02-CLASSINJ) ----
        // Validate entityClass against the known #[Shared] classes before any find()/fan-out.
        // An unexpected class throws UnrecoverableExceptionInterface so Messenger does NOT retry.
        $sharedClasses = $this->copier->findSharedClasses($this->landlordEm);
        if (!\in_array($class, $sharedClasses, true)) {
            $this->logger->error('tenancy.shared_entity_async_unknown_class', [
                'entity_class' => $class,
                'identifier' => $identifier,
            ]);

            throw new class(sprintf('tenancy: SharedEntityChangedMessage carries unknown entity class "%s". Expected one of: %s. Message will NOT be retried (UnrecoverableExceptionInterface).', $class, implode(', ', $sharedClasses))) extends \RuntimeException implements UnrecoverableExceptionInterface {
            };
        }

        // ---- Stale-read mitigation (Pitfall 3, T-27-02-STALE) ----
        // ORDERING IS MANDATORY: clear() BEFORE find(). A find() before clear() would return a
        // stale identity-map row in a long-running worker instead of hitting the DB.
        // NOTE: Doctrine ORM 3 removed the per-class clear($class) overload — clear() clears
        // the entire identity map. This is acceptable in a worker context where the handler is
        // the only consumer of the landlord EM during this invocation.
        $this->landlordEm->clear();

        // ---- Re-fetch latest landlord state (D-05) ----
        $landlordEntity = ('delete' !== $changeType)
            ? $this->landlordEm->find($class, $identifier)
            : null;

        // ---- Vanished-row → delete (D-04) ----
        // The landlord row is gone by handle time for an insert/update — master deletion won the
        // race. Propagate a tenant-side delete rather than leaving a permanent orphan.
        $effectiveType = $changeType;
        if (null === $landlordEntity && 'delete' !== $changeType) {
            $this->logger->warning('tenancy.shared_entity_async_vanished_row', [
                'entity_class' => $class,
                'identifier' => $identifier,
                'original_type' => $changeType,
            ]);
            $effectiveType = 'delete';
        }

        // ---- Per-tenant fan-out ----
        $previousTenant = $this->tenantContext->hasTenant() ? $this->tenantContext->getTenant() : null;

        // WR-04: materialize tenants once — a Generator-backed findAll() exhausts after the
        // first iteration; outer-by-tenant also lets us reset the EM only once per tenant.
        $tenants = [];
        foreach ($this->tenantProvider->findAll() as $tenant) {
            $tenants[] = $tenant;
        }

        /** @var list<string> $failures */
        $failures = [];

        try {
            foreach ($tenants as $tenant) {
                $tenantEm = $this->switcher->switchTo($tenant);
                try {
                    if ('delete' === $effectiveType) {
                        // OQ-1 resolution: deleteRow() accepts class + capturedIds without
                        // requiring a live entity object. NEVER construct \stdClass here.
                        $this->copier->deleteRow($tenantEm, $class, $identifier);
                    } else {
                        // $landlordEntity is guaranteed non-null here: D-04 (above) collapsed
                        // every null to effectiveType='delete', which routes to deleteRow().
                        // PHPStan can confirm this via the control-flow; the assert makes it explicit.
                        \assert(null !== $landlordEntity);
                        $this->copier->applyRow($this->landlordEm, $tenantEm, $landlordEntity, $effectiveType, null);
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('tenancy.shared_entity_async_fan_out_failed', [
                        'tenant_slug' => $tenant->getSlug(),
                        'entity_class' => $class,
                        'identifier' => $identifier,
                        'error' => $e->getMessage(),
                    ]);
                    $failures[] = $tenant->getSlug();
                    // A failed flush closes the EM; reset it so the next tenant starts fresh.
                    $this->registry->resetManager('tenant');
                }
            }
        } finally {
            // CR-01/CR-02: always restore tenant context (and close connection) after the loop.
            $this->switcher->restore($previousTenant);
        }

        // ---- Throw to retry (D-02) ----
        // Best-effort: all tenants were attempted. Now throw if any failed so Messenger's
        // retry_strategy re-enqueues the message for another attempt.
        if ([] !== $failures) {
            throw new SharedEntityAsyncFanOutException(sprintf('Async shared entity fan-out failed for %d tenant(s): %s. Message will be retried per transport retry_strategy.', \count($failures), implode(', ', $failures)));
        }
    }
}
