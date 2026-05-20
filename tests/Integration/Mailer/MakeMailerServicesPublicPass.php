<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Mailer;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Test-only compiler pass that exposes Phase 20 Mailer services so
 * integration tests can fetch them via $container->get().
 *
 * Service IDs that are not (yet) registered in this kernel are skipped
 * silently — the pass tolerates missing definitions via hasDefinition /
 * hasAlias guards. This lets the same pass live alongside both stripped-down
 * test kernels and the fully-wired container.
 *
 * No-op when symfony/mailer is not installed.
 *
 * Extracted from MailerTestKernel.php (Plan 00 co-located it) for Plan 06 —
 * a second consumer (ReplaceTenantTransportFactoryPass + AsyncCanaryTest)
 * justifies the extraction.
 */
final class MakeMailerServicesPublicPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!interface_exists(MailerInterface::class)) {
            return;
        }

        $ids = [
            // Bundle core
            'tenancy.context',
            'tenancy.provider',
            'tenancy.bootstrapper_chain',
            // Phase 20 mailer services
            'tenancy.mailer.lru_cache',
            'tenancy.mailer.transports_decorator',
            'tenancy.mailer.message_decorator',
            'tenancy.mailer.sanitizing_decorator',
            'tenancy.mailer.bootstrapper',
            'tenancy.mailer.context_cleared_listener',
            // Symfony mailer surface
            'mailer',
            'mailer.transports',
            'mailer.default_transport',
            // Symfony framework / messenger surface for async canary
            'event_dispatcher',
            'messenger.default_bus',
            'messenger.bus.default',
        ];

        foreach ($ids as $id) {
            if ($container->hasDefinition($id)) {
                $container->getDefinition($id)->setPublic(true);
            } elseif ($container->hasAlias($id)) {
                $container->getAlias($id)->setPublic(true);
            }
        }
    }
}
