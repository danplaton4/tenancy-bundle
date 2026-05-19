<?php

declare(strict_types=1);

namespace Tenancy\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;

/**
 * Compile-time guard for the per-tenant Mailer wiring (BOOT-04-f).
 *
 * 1. If Mailer is installed and Messenger is configured to route SendEmailMessage
 *    asynchronously (auto-detected via framework.messenger.routing), the bundle
 *    requires the X-Transport strategy (TenantMessageDecorator) to be wired —
 *    without it, the worker-side dispatch has no signal to identify the tenant
 *    and emails silently fall back to the landlord transport.
 * 2. The config flag tenancy.mailer.async accepts: 'auto' (detect), 'true'
 *    (force the check), 'false' (skip the check — escape hatch).
 * 3. Missing or invalid values for the parameter raise descriptive
 *    \LogicException at build time so the misconfiguration cannot reach
 *    production.
 */
final class MailerTransportContractPass implements CompilerPassInterface
{
    /**
     * Service ID for the TenantMessageDecorator. MUST match the ID registered
     * in config/services.php — drift between the two locations breaks the
     * compile-time guard.
     */
    private const X_TRANSPORT_SERVICE = 'tenancy.mailer.message_decorator';

    /**
     * Container parameter holding the user's tenancy.mailer.async config value.
     */
    private const ASYNC_PARAM = 'tenancy.mailer.async';

    public function process(ContainerBuilder $container): void
    {
        if (!interface_exists(\Symfony\Component\Mailer\MailerInterface::class)) {
            return;
        }

        if (!$container->hasParameter(self::ASYNC_PARAM)) {
            throw new \LogicException(sprintf('tenancy: parameter "%s" must be declared (allowed values: "auto", "true", "false"). Add it under tenancy.mailer.async in your bundle config or remove symfony/mailer from your dependencies.', self::ASYNC_PARAM));
        }

        $asyncRaw = $container->getParameter(self::ASYNC_PARAM);
        if (!is_scalar($asyncRaw)) {
            throw new \LogicException(sprintf('tenancy: parameter "%s" must be a scalar value ("auto", "true", or "false"); got %s.', self::ASYNC_PARAM, get_debug_type($asyncRaw)));
        }
        $async = (string) $asyncRaw;

        $isAsync = match ($async) {
            'true' => true,
            'false' => false,
            'auto' => $this->detectAsyncRouting($container),
            default => throw new \LogicException(sprintf('tenancy: parameter "%s" must be one of "auto", "true", "false"; got "%s".', self::ASYNC_PARAM, $async)),
        };

        if (!$isAsync) {
            return;
        }

        if (!$container->hasDefinition(self::X_TRANSPORT_SERVICE)) {
            throw new \LogicException('tenancy: Mailer is routed async via Messenger (framework.messenger.routing maps SendEmailMessage) but the X-Transport strategy is not configured. Service "'.self::X_TRANSPORT_SERVICE.'" (TenantMessageDecorator) is required for the worker to identify which tenant\'s SMTP transport to use. Set tenancy.mailer.async: false to skip this check (NOT recommended — async dispatch will fall back to landlord transport).');
        }
    }

    private function detectAsyncRouting(ContainerBuilder $container): bool
    {
        if (!class_exists(SendEmailMessage::class)) {
            return false;
        }

        $target = ltrim(SendEmailMessage::class, '\\');

        /** @var array<int, array<string, mixed>> $configs */
        $configs = $container->getExtensionConfig('framework');
        foreach ($configs as $config) {
            $messenger = $config['messenger'] ?? null;
            if (!is_array($messenger)) {
                continue;
            }
            $routing = $messenger['routing'] ?? null;
            if (!is_array($routing)) {
                continue;
            }

            if (isset($routing[SendEmailMessage::class])) {
                return true;
            }

            // Some users use the FQCN as array key normalized differently —
            // accept both leading-backslash and no-backslash variants.
            foreach (array_keys($routing) as $messageClass) {
                if (is_string($messageClass) && ltrim($messageClass, '\\') === $target) {
                    return true;
                }
            }
        }

        return false;
    }
}
