<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Filesystem;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Tenancy\Bundle\TenancyBundle;

/**
 * Minimal Symfony kernel for Phase 24 Filesystem Bootstrapper integration tests.
 *
 * Registers FrameworkBundle + DoctrineBundle (guarded) + FlysystemBundle (guarded)
 * + TenancyBundle with two memory-adapter storages:
 *   - users.storage  — TAGGED tenancy.scoped (strategy: prefix, prefix_template:
 *                      "tenant_{slug}/") per CONTEXT §DEC-FILE-MULTI; this is the
 *                      "uploads" storage that scopes per tenant.
 *   - public.storage — UNTAGGED → bypasses Phase 24 scoping entirely (landlord-only
 *                      asset path; DEC-FILE-MULTI's "escape hatch" for shared
 *                      filesystems).
 *
 * Doctrine ORM is wired against an in-memory SQLite so the TenancyBundle's
 * landlord EntityManager + Tenant entity discovery work end-to-end without
 * external infrastructure.
 *
 * Bundles are conditionally registered behind class_exists / interface_exists
 * so a `--no-dev` install (without flysystem-bundle / doctrine-bundle) does
 * not break the kernel class autoload.
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

        // Tag users.storage with tenancy.scoped (strategy: prefix,
        // prefix_template: "tenant_{slug}/") at the BEFORE_OPTIMIZATION stage,
        // AFTER FlysystemExtension has built the storage Definition. Compiler
        // pass is the canonical attach-tag-to-bundle-supplied-service pattern
        // (registerContainerConfiguration closures all run BEFORE extension
        // processing, so they cannot see users.storage yet).
        $container->addCompilerPass(new ScopedStorageTaggingPass());

        // Expose private storage services (`users.storage`, `public.storage`) +
        // any Phase 24 tenancy.filesystem.* services that subsequent waves wire,
        // so integration tests can call $container->get() directly.
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
                        'public.storage' => [
                            'adapter' => 'memory',
                        ],
                    ],
                ]);
            }

            $container->loadFromExtension('tenancy', [
                'driver' => 'shared_database',
                'strict_mode' => false,
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
