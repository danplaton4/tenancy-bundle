<?php

declare(strict_types=1);

namespace Tenancy\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Compile-time guard for the async shared-entity fan-out wiring (SHARE-03 / D-06).
 *
 * Mirrors MailerTransportContractPass structurally — the same 3-stage guard pattern:
 *  1. Short-circuit when tenancy.shared.async parameter is absent (shared stack not loaded).
 *  2. Short-circuit when tenancy.shared.async is false (async not opted into).
 *  3. Throw a descriptive \LogicException when async:true but symfony/messenger is absent.
 *
 * The throw MUST be the last stage so it only fires for the specific misconfiguration
 * of async:true + Messenger absent — never for async:false or missing parameter.
 *
 * @see MailerTransportContractPass — analog for the same guard pattern
 */
final class SharedAsyncContractPass implements CompilerPassInterface
{
    /**
     * Container parameter holding the user's tenancy.shared.async config value.
     */
    private const ASYNC_PARAM = 'tenancy.shared.async';

    public function process(ContainerBuilder $container): void
    {
        // Stage 1: short-circuit when the shared stack is absent (parameter not set).
        if (!$container->hasParameter(self::ASYNC_PARAM)) {
            return;
        }

        // Stage 2: short-circuit when async mode is disabled (the sync-only default path).
        if (!(bool) $container->getParameter(self::ASYNC_PARAM)) {
            return;
        }

        // Stage 3 (D-06): async:true but symfony/messenger is not installed — fail loud at
        // build time so this misconfiguration can NEVER reach a running worker.
        if (!interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
            throw new \LogicException('tenancy: tenancy.shared.async is set to true but symfony/messenger is not installed. Run "composer require symfony/messenger" or set "tenancy.shared.async: false" in your bundle config.');
        }
    }
}
