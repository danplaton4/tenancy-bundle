<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Resolver;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Event\TenantResolved;
use Tenancy\Bundle\Provider\TenantProviderInterface;

#[AsEventListener(event: ConsoleEvents::COMMAND, method: 'onConsoleCommand')]
final class ConsoleResolver
{
    public function __construct(
        private readonly TenantProviderInterface $tenantProvider,
        private readonly TenantContext $tenantContext,
        private readonly BootstrapperChain $bootstrapperChain,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();
        $input = $event->getInput();

        if (null === $command) {
            return;
        }

        $application = $command->getApplication();
        if (null === $application) {
            return;
        }

        // Add --tenant to Application definition if not already present.
        // This must happen before we try to read the option value because the
        // input is already bound at this point — without adding the option to
        // the Application definition and rebinding, Symfony throws
        // InvalidArgumentException: The "tenant" option does not exist.
        $appDefinition = $application->getDefinition();
        if (!$appDefinition->hasOption('tenant')) {
            $appDefinition->addOption(
                new InputOption('tenant', null, InputOption::VALUE_OPTIONAL, 'Tenant slug to resolve')
            );
        }

        // Rebind input against the updated definition so --tenant is parsed
        $command->mergeApplicationDefinition();
        $input->bind($command->getDefinition());

        $slug = $input->getOption('tenant');

        if (!\is_string($slug) || '' === $slug) {
            return; // Silent — no tenant context when --tenant is absent or empty
        }

        $tenant = $this->tenantProvider->findBySlug($slug);
        $this->tenantContext->setTenant($tenant);
        $this->bootstrapperChain->boot($tenant);
        $this->eventDispatcher->dispatch(
            new TenantResolved($tenant, null, self::class)
        );
    }
}
