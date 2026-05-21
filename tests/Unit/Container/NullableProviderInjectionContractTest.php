<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Command\TenantRunCommand;
use Tenancy\Bundle\Mailer\TenantAwareTransportsDecorator;
use Tenancy\Bundle\Messenger\TenantWorkerMiddleware;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\Resolver\ConsoleResolver;
use Tenancy\Bundle\Resolver\HeaderResolver;
use Tenancy\Bundle\Resolver\HostResolver;
use Tenancy\Bundle\Resolver\QueryParamResolver;

/**
 * Contract test for the zero-config bootability invariant (DX-06).
 *
 * Every service in config/services.php that consumes `tenancy.provider`
 * via `->nullOnInvalid()` MUST have a constructor whose corresponding
 * positional parameter accepts `?TenantProviderInterface`. If a future
 * contributor drops the `?` on any of these constructors, the container
 * will resolve `nullOnInvalid()` to literal null on a fresh-skeleton
 * boot and PHP strict typing will throw a TypeError at cache:clear time
 * — the exact regression Phase 18 closed.
 *
 * The reflection check below locks that invariant. The
 * services.php-shape sanity check at the bottom prevents new
 * `nullOnInvalid()` consumers from being added without also being
 * registered here.
 */
final class NullableProviderInjectionContractTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: int}>
     */
    public static function provideTenancyProviderConsumers(): array
    {
        // Each entry: [Fully-qualified class name, zero-indexed constructor param position]
        // Sourced by reading config/services.php for every
        //     service('tenancy.provider')->nullOnInvalid()
        // injection. Order matches services.php top-to-bottom.
        return [
            'HostResolver' => [HostResolver::class, 0],
            'HeaderResolver' => [HeaderResolver::class, 0],
            'QueryParamResolver' => [QueryParamResolver::class, 0],
            'ConsoleResolver' => [ConsoleResolver::class, 0],
            'TenantRunCommand' => [TenantRunCommand::class, 0],
            'TenantWorkerMiddleware' => [TenantWorkerMiddleware::class, 2],
            'TenantAwareTransportsDecorator' => [TenantAwareTransportsDecorator::class, 1],
        ];
    }

    #[DataProvider('provideTenancyProviderConsumers')]
    public function testConsumerHasNullableTenantProviderParam(string $class, int $position): void
    {
        $reflection = new \ReflectionClass($class);
        $ctor = $reflection->getConstructor();
        self::assertNotNull($ctor, sprintf('%s has no constructor — cannot verify nullable-provider invariant', $class));

        $params = $ctor->getParameters();
        self::assertGreaterThan(
            $position,
            count($params),
            sprintf('%s constructor has fewer than %d parameters; nullable-provider contract test is stale.', $class, $position + 1)
        );

        $param = $params[$position];
        $type = $param->getType();

        self::assertInstanceOf(
            \ReflectionNamedType::class,
            $type,
            sprintf('%s::__construct param #%d ("%s") must have a single named type, not a union/intersection — needed to lock the nullable-TenantProviderInterface contract.', $class, $position, $param->getName())
        );

        self::assertSame(
            TenantProviderInterface::class,
            $type->getName(),
            sprintf('%s::__construct param #%d ("%s") expected type %s, got %s. config/services.php injects tenancy.provider here via nullOnInvalid().', $class, $position, $param->getName(), TenantProviderInterface::class, $type->getName())
        );

        self::assertTrue(
            $type->allowsNull(),
            sprintf('%s::__construct param #%d ("%s") MUST be nullable (?TenantProviderInterface). Zero-config Symfony skeletons have no `tenancy:` config, so the DI container resolves nullOnInvalid() to literal null. Dropping the `?` here resurrects the v0.1.0..v0.2.1 TypeError regression closed by Phase 18. See DX-06 / 18-VERIFICATION.md.', $class, $position, $param->getName())
        );
    }

    /**
     * Sanity check: the consumer list above must match the actual count
     * of `tenancy.provider`->nullOnInvalid() sites in config/services.php.
     *
     * If a contributor adds a new `nullOnInvalid()` site for tenancy.provider
     * without also adding its (class, position) tuple to the data provider
     * above, this test fails — surfacing the gap loudly.
     */
    public function testServicesPhpNullOnInvalidProviderSitesAreAllRegistered(): void
    {
        $servicesPhpPath = \dirname(__DIR__, 3).'/config/services.php';
        self::assertFileExists($servicesPhpPath, 'config/services.php is missing — cannot verify contract');

        $contents = (string) file_get_contents($servicesPhpPath);

        // Count occurrences of service('tenancy.provider')->nullOnInvalid()
        // tolerant to whitespace variations around the chain
        $matchCount = preg_match_all(
            "/service\\(\\s*['\"]tenancy\\.provider['\"]\\s*\\)\\s*->\\s*nullOnInvalid\\(\\)/",
            $contents
        );

        $registeredCount = \count(self::provideTenancyProviderConsumers());

        self::assertSame(
            $registeredCount,
            $matchCount,
            sprintf(
                "config/services.php has %d `service('tenancy.provider')->nullOnInvalid()` site(s) but provideTenancyProviderConsumers() only registers %d. Either add the new consumer to the data provider (and the reflection test will lock its constructor contract), or remove the unused nullOnInvalid().",
                $matchCount,
                $registeredCount
            )
        );
    }
}
