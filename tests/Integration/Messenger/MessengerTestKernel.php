<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Messenger;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Tenancy\Bundle\TenancyBundle;
use Tenancy\Bundle\Tests\Integration\Messenger\Support\MakeMessengerServicesPublicPass;
use Tenancy\Bundle\Tests\Integration\Messenger\Support\ReplaceProviderWithStubPass;

/**
 * Minimal Symfony kernel for Messenger middleware integration tests.
 *
 * Registers FrameworkBundle + TenancyBundle with:
 *   - Messenger enabled with default bus and allow_no_handlers: true
 *   - No Doctrine bundle (not needed for Messenger middleware tests)
 *   - MakeMessengerServicesPublicPass to expose private services for test inspection
 *   - ReplaceProviderWithStubPass to replace DoctrineTenantProvider with StubTenantProvider
 */
class MessengerTestKernel extends Kernel
{
    public function __construct(string $environment = 'messenger_test', bool $debug = false)
    {
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new TenancyBundle(),
        ];
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new MakeMessengerServicesPublicPass());
        $container->addCompilerPass(new ReplaceProviderWithStubPass());
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret'                => 'test',
                'test'                  => true,
                'http_method_override'  => false,
                'handle_all_throwables' => true,
                'php_errors'            => ['log' => true],
                'messenger'             => [
                    'default_bus' => 'messenger.bus.default',
                    'buses'       => [
                        'messenger.bus.default' => [
                            'default_middleware' => 'allow_no_handlers',
                        ],
                    ],
                ],
            ]);

            $container->loadFromExtension('tenancy', [
                'driver'      => 'database_per_tenant',
                'strict_mode' => false,
            ]);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/tenancy_messenger_test/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/tenancy_messenger_test/logs';
    }
}
