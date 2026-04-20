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
use Tenancy\Bundle\Event\TenantResolved;
use Tenancy\Bundle\Resolver\ResolverChain;

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
        private readonly ResolverChain $resolverChain,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $resolution = $this->resolverChain->resolve($event->getRequest());

        if (null === $resolution) {
            // Public route / landlord / health check — leave TenantContext empty,
            // skip the bootstrapper chain, do NOT dispatch TenantResolved.
            return;
        }

        $this->tenantContext->setTenant($resolution->tenant);
        $this->bootstrapperChain->boot($resolution->tenant);
        $this->eventDispatcher->dispatch(
            new TenantResolved($resolution->tenant, $event->getRequest(), $resolution->resolvedBy)
        );
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
