<?php

declare(strict_types=1);

namespace Tenancy\Bundle\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Event\TenantContextCleared;

#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: TenantContextOrchestrator::PRIORITY)]
#[AsEventListener(event: KernelEvents::TERMINATE, method: 'onKernelTerminate')]
final class TenantContextOrchestrator
{
    /** Priority 20: after Router (32), before Security firewall (8). */
    public const PRIORITY = 20;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly BootstrapperChain $bootstrapperChain,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // Phase 2 will inject ResolverChain here to resolve tenant from request.
        // Once resolved: $this->tenantContext->setTenant($tenant); $this->bootstrapperChain->boot($tenant); dispatch TenantResolved event.
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (!$this->tenantContext->hasTenant()) {
            return;
        }

        $this->bootstrapperChain->clear();
        $this->tenantContext->clear();
        $this->eventDispatcher->dispatch(new TenantContextCleared());
    }
}
