<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Mailer;

/**
 * Test-only factory that produces a Closure compatible with
 * TenantAwareTransportsDecorator's 6th constructor argument (transportFactory).
 *
 * In production, the decorator's default factory delegates to
 * Symfony\Component\Mailer\Transport::fromDsn — which builds a real
 * EsmtpTransport and tries to open an SMTP socket on first send(). For the
 * async canary test we instead build a SpyTransport: it records every send()
 * call together with the DSN the spy was instantiated with, enabling the
 * canary to assert WHICH tenant DSN the worker actually used.
 *
 * The factory also registers each constructed spy's DSN with
 * SpyTransportRegistry, so AsyncCanaryTest can observe transport instantiation
 * across the full MessageBus serialize → deserialize → handler → Transports::send
 * chain.
 *
 * Decorator constructor positions (verified against
 * src/Mailer/TenantAwareTransportsDecorator.php):
 *   0=inner, 1=provider, 2=cache, 3=context, 4=eventDispatcher, 5=transportFactory
 */
final class SpyTransportFactory
{
    /**
     * @return \Closure(string, mixed=): SpyTransport
     */
    public static function create(): \Closure
    {
        return static function (string $dsn, mixed $dispatcher = null): SpyTransport {
            unset($dispatcher); // dispatcher pass-through unused by the spy

            $transport = new SpyTransport($dsn);
            SpyTransportRegistry::record($dsn);

            return $transport;
        };
    }
}
