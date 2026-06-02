<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Filesystem;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Tenancy\Bundle\TenancyBundle;

/**
 * Minimal Symfony kernel for Phase 24 Filesystem Bootstrapper integration tests.
 *
 * Registers FrameworkBundle + DoctrineBundle (guarded) + FlysystemBundle (guarded)
 * + TenancyBundle with THREE memory-adapter storages:
 *   - users.storage         — TAGGED tenancy.scoped (strategy: prefix,
 *                             prefix_template: "tenant_{slug}/")
 *   - tenant_buckets.storage — TAGGED tenancy.scoped (strategy: per_tenant_adapter)
 *   - public.storage        — UNTAGGED → bypasses Phase 24 scoping entirely
 *
 * tenancy.filesystem is enabled with allow_per_tenant_adapter: true and
 * cache_size: 2 (deliberately low so the 100-tenant LRU test forces evictions
 * deterministically — only 2 adapters live in the cache at any time, so
 * 100 tenants always produce >= 98 evictions).
 *
 * The `tenancy.provider` definition is swapped out by ReplaceFilesystemProviderPass
 * for StubFilesystemTenantProvider which pre-seeds:
 *   - 'acme' / 'globex' → filesystemConfig = ['adapter_dsn' => 'memory://']
 *   - 'broken'          → filesystemConfig = null (triggers MissingFilesystemConfigException)
 *   - 'tenant_000'–'tenant_099' → filesystemConfig = ['adapter_dsn' => 'memory://']
 *
 * Doctrine ORM is wired against an in-memory SQLite so the TenancyBundle's
 * landlord EntityManager + Tenant entity discovery work end-to-end without
 * external infrastructure.
 *
 * Bundles are conditionally registered behind class_exists / interface_exists
 * so a `--no-dev` install (without flysystem-bundle / doctrine-bundle) does
 * not break the kernel class autoload.
 *
 * Pass registration order (critical):
 *   ScopedStorageTaggingPass (priority 10)  — tags storage services BEFORE
 *   FilesystemContractPass (bundle, default) — walks findTaggedServiceIds()
 * Both run at TYPE_BEFORE_OPTIMIZATION; higher priority means earlier execution.
 */
class FilesystemTestKernel extends Kernel
{
    public function __construct(string $environment = 'filesystem_test', bool $debug = false)
    {
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        $bundles = [new FrameworkBundle()];

        if (class_exists(\Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class)) {
            $bundles[] = new \Doctrine\Bundle\DoctrineBundle\DoctrineBundle();
        }

        if (class_exists(\League\FlysystemBundle\FlysystemBundle::class)) {
            $bundles[] = new \League\FlysystemBundle\FlysystemBundle();
        }

        $bundles[] = new TenancyBundle();

        return $bundles;
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Swap tenancy.provider for the filesystem stub that yields tenants
        // with filesystemConfig pre-seeded.
        $container->addCompilerPass(new ReplaceFilesystemProviderPass());

        // Tag users.storage and tenant_buckets.storage with tenancy.scoped at
        // priority 10 (higher than default 0) so this pass runs BEFORE the
        // production FilesystemContractPass (added by TenancyBundle::build() at
        // priority 0) that walks findTaggedServiceIds('tenancy.scoped').
        //
        // registerContainerConfiguration closures run BEFORE extension
        // processing so they cannot see bundle-built definitions. The compiler
        // pass is the canonical tag-attachment pattern (Plan 24-00 deviation
        // analysis, confirmed in 24-00-SUMMARY §Deviations).
        $container->addCompilerPass(
            new ScopedStorageTaggingPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            10,
        );

        // Expose private storage services + Phase 24 tenancy.filesystem.* service
        // IDs so integration tests can call $container->get() directly.
        $container->addCompilerPass(new MakeFilesystemServicesPublicPass());
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'filesystem-test',
                'test' => true,
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
            ]);

            if (class_exists(\Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class)) {
                $container->loadFromExtension('doctrine', [
                    'dbal' => [
                        'driver' => 'pdo_sqlite',
                        'url' => 'sqlite:///:memory:',
                    ],
                    'orm' => [
                        'mappings' => [
                            'TenancyBundle' => [
                                'is_bundle' => false,
                                'type' => 'attribute',
                                'dir' => realpath(__DIR__.'/../../../src/Entity') ?: __DIR__.'/../../../src/Entity',
                                'prefix' => 'Tenancy\\Bundle\\Entity',
                                'alias' => 'TenancyBundle',
                            ],
                        ],
                    ],
                ]);
            }

            if (class_exists(\League\FlysystemBundle\FlysystemBundle::class)) {
                $container->loadFromExtension('flysystem', [
                    'storages' => [
                        'users.storage' => [
                            'adapter' => 'memory',
                        ],
                        'tenant_buckets.storage' => [
                            'adapter' => 'memory',
                        ],
                        'public.storage' => [
                            'adapter' => 'memory',
                        ],
                    ],
                ]);
            }

            $container->loadFromExtension('tenancy', [
                'driver' => 'shared_database',
                'strict_mode' => false,
                'filesystem' => [
                    'enabled' => true,
                    'allow_per_tenant_adapter' => true,
                    'cache_size' => 2,
                ],
            ]);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tenancy_filesystem_test_'.md5(static::class).'_'.$this->environment.'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_filesystem_test_'.md5(static::class).'_'.$this->environment.'/logs';
    }
}
