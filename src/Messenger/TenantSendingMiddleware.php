<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Tenancy\Bundle\Context\TenantContext;

final class TenantSendingMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $tenant = $this->tenantContext->getTenant();
        if (null === $envelope->last(TenantStamp::class) && null !== $tenant) {
            $envelope = $envelope->with(
                new TenantStamp($tenant->getSlug())
            );
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
