<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Mailer;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Tenancy\Bundle\TenancyBundle;
use Tenancy\Bundle\Tests\Integration\Support\ReplaceTenancyProviderPass;

/**
 * Minimal Symfony kernel for Mailer + Messenger integration tests (Phase 20).
 *
 * Registers FrameworkBundle + TenancyBundle with:
 *   - framework.mailer.dsn = null://null (landlord default — never used by tests if
 *     a tenant DSN is resolved, but provides a safe fallback)
 *   - Messenger enabled with default bus + allow_no_handlers, with the
 *     SendEmailMessage routing reserved for Plan 06 (async canary).
 *   - MakeMailerServicesPublicPass: exposes Phase-20 services for $container->get().
 *
 * Wave 1 plans may extend this kernel with additional configuration; Wave 0
 * only needs the file to exist and be syntactically valid so AsyncCanaryTest's
 * setUpBeforeClass can boot it (or markTestIncomplete out before doing so).
 */
class MailerTestKernel extends Kernel
{
    public function __construct(string $environment = 'mailer_test', bool $debug = false)
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
        // Replace real tenancy.provider (needs Doctrine EM + cache) with NullTenantProvider —
        // mirrors tests/Integration/TestKernel so the container compiles without Doctrine
        // ORM/DBAL bundles configured. Plan 06 (async canary) may add a real StubTenantProvider
        // override that runs at a later compiler-pass priority.
        $container->addCompilerPass(new ReplaceTenancyProviderPass());
        $container->addCompilerPass(new MakeMailerServicesPublicPass());
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test',
                'test' => true,
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
                'mailer' => [
                    'dsn' => 'null://null',
                ],
                'messenger' => [
                    'default_bus' => 'messenger.bus.default',
                    'buses' => [
                        'messenger.bus.default' => [
                            'default_middleware' => 'allow_no_handlers',
                        ],
                    ],
                ],
            ]);

            $container->loadFromExtension('tenancy', [
                'driver' => 'database_per_tenant',
                'strict_mode' => false,
            ]);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tenancy_mailer_test/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_mailer_test/logs';
    }
}
