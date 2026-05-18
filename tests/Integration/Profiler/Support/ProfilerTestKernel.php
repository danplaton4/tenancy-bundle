<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Profiler\Support;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Tenancy\Bundle\TenancyBundle;
use Tenancy\Bundle\Tests\Integration\Support\ReplaceTenancyProviderPass;

/**
 * Test kernel for Profiler integration tests (WebProfilerBundle WDT).
 *
 * Boots FrameworkBundle + TwigBundle + WebProfilerBundle + TenancyBundle with
 * debug=true (the default) so the profiler service graph is fully realized.
 *
 * Cache dir is keyed by static::class + environment + debug so multiple kernel
 * instances (e.g. Plan 05 compile-out test booting with debug=false) do not
 * collide with profiler-enabled boots.
 */
final class ProfilerTestKernel extends Kernel
{
    public function __construct(string $environment = 'test', bool $debug = true)
    {
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new TwigBundle(),
            new WebProfilerBundle(),
            new TenancyBundle(),
        ];
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new ReplaceTenancyProviderPass());
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test-secret',
                'test' => true,
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
                'profiler' => ['enabled' => true, 'collect' => true],
                'router' => ['utf8' => true, 'resource' => 'kernel::loadRoutes', 'type' => 'service'],
            ]);

            $container->loadFromExtension('twig', [
                'default_path' => '%kernel.project_dir%/tests/Integration/Profiler/Support/templates',
                'strict_variables' => true,
            ]);

            $container->loadFromExtension('web_profiler', [
                'toolbar' => true,
                'intercept_redirects' => false,
            ]);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tenancy_bundle_profiler_test_'.md5(static::class).'_'.$this->environment.'_'.($this->debug ? 'debug' : 'nodebug').'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_bundle_profiler_test_'.md5(static::class).'_'.$this->environment.'_'.($this->debug ? 'debug' : 'nodebug').'/logs';
    }
}
