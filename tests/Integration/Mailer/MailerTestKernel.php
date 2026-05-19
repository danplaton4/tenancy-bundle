<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Mailer;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Tenancy\Bundle\TenancyBundle;

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

/**
 * Compiler pass that exposes the (future) Phase 20 Mailer services so
 * integration tests can fetch them via $container->get(). Service IDs
 * referenced here will be created in Wave 1 plans — the pass tolerates
 * missing definitions (hasDefinition guard) so it is safe to add now.
 */
final class MakeMailerServicesPublicPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $ids = [
            'tenancy.context',
            'tenancy.bootstrapper_chain',
            'tenancy.provider',
            // Phase 20 service IDs — registered in Wave 1+
            'tenancy.mailer.bootstrapper',
            'tenancy.mailer.message_decorator',
            'tenancy.mailer.transports_decorator',
            'tenancy.mailer.lru_cache',
            'tenancy.mailer.sanitizing_decorator',
            'mailer.transports',
            'mailer.default_transport',
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
