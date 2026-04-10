<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Event\TenantContextCleared;
use Tenancy\Bundle\Provider\TenantProviderInterface;

final class TenantWorkerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly BootstrapperChain $bootstrapperChain,
        private readonly TenantProviderInterface $tenantProvider,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $stamp = $envelope->last(TenantStamp::class);

        if (null === $stamp) {
            return $stack->next()->handle($envelope, $stack);
        }

        $tenant = $this->tenantProvider->findBySlug($stamp->getTenantSlug());
        $this->tenantContext->setTenant($tenant);
        $this->bootstrapperChain->boot($tenant);

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->bootstrapperChain->clear();
            $this->tenantContext->clear();
            $this->eventDispatcher->dispatch(new TenantContextCleared());
        }
    }
}
