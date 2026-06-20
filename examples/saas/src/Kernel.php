<?php

declare(strict_types=1);

namespace App;

use App\DependencyInjection\Compiler\ScopedStorageTaggingPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        // Tag flysystem's `users.storage` for per-tenant scoping before the
        // bundle's FilesystemContractPass (priority 0) reads the tag.
        $container->addCompilerPass(new ScopedStorageTaggingPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
    }
}
