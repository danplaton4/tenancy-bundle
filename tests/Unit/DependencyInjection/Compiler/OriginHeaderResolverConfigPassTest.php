<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tenancy\Bundle\DependencyInjection\Compiler\OriginHeaderResolverConfigPass;

final class OriginHeaderResolverConfigPassTest extends TestCase
{
    public function testNoOpWhenOriginNotInResolvers(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('tenancy.resolvers', ['host', 'header']);
        // tenancy.origin.allow_list intentionally unset.

        (new OriginHeaderResolverConfigPass())->process($container);

        $this->assertFalse($container->hasParameter('tenancy.origin.allow_list'));
    }

    public function testNoOpWhenResolversParameterAbsent(): void
    {
        $container = new ContainerBuilder();

        (new OriginHeaderResolverConfigPass())->process($container);

        $this->assertFalse($container->hasParameter('tenancy.origin.allow_list'));
    }

    public function testThrowsOnEmptyAllowListWhenOriginConfigured(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('tenancy.resolvers', ['host', 'origin']);
        $container->setParameter('tenancy.origin.allow_list', []);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tenancy.origin.allow_list is empty but "origin" is configured in tenancy.resolvers');

        (new OriginHeaderResolverConfigPass())->process($container);
    }

    public function testThrowsOnMissingAllowListParameter(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('tenancy.resolvers', ['origin']);
        // No tenancy.origin.allow_list parameter.

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tenancy.origin.allow_list is empty');

        (new OriginHeaderResolverConfigPass())->process($container);
    }

    public function testThrowsOnUnparseableOriginUrl(): void
    {
        $container = $this->containerWith([['origin' => 'not a url', 'slug' => 'x']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is unparseable — must be an absolute origin URL');

        (new OriginHeaderResolverConfigPass())->process($container);
    }

    public function testThrowsOnSchemeOtherThanHttpHttps(): void
    {
        $container = $this->containerWith([['origin' => 'ftp://acme.example.com', 'slug' => 'acme']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is unparseable');

        (new OriginHeaderResolverConfigPass())->process($container);
    }

    public function testThrowsOnMidStringWildcard(): void
    {
        $container = $this->containerWith([['origin' => 'https://app.*.example.com', 'slug' => null]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contains a mid-string wildcard — only one leftmost label may be "*"');

        (new OriginHeaderResolverConfigPass())->process($container);
    }

    public function testThrowsOnMultiLabelWildcard(): void
    {
        $container = $this->containerWith([['origin' => 'https://*.*.example.com', 'slug' => null]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contains a mid-string wildcard');

        (new OriginHeaderResolverConfigPass())->process($container);
    }

    public function testThrowsOnPureStarWildcard(): void
    {
        $container = $this->containerWith([['origin' => 'https://*', 'slug' => null]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contains a mid-string wildcard');

        (new OriginHeaderResolverConfigPass())->process($container);
    }

    public function testThrowsOnPathInOrigin(): void
    {
        $container = $this->containerWith([['origin' => 'https://acme.app.example.com/api', 'slug' => 'acme']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contains a path/query — origin URLs must be bare authorities');

        (new OriginHeaderResolverConfigPass())->process($container);
    }

    public function testThrowsOnQueryInOrigin(): void
    {
        $container = $this->containerWith([['origin' => 'https://acme.app.example.com?x=1', 'slug' => 'acme']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contains a path/query');

        (new OriginHeaderResolverConfigPass())->process($container);
    }

    public function testThrowsOnUserInfoInOrigin(): void
    {
        $container = $this->containerWith([['origin' => 'https://user:pass@acme.app.example.com', 'slug' => 'acme']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contains a path/query');

        (new OriginHeaderResolverConfigPass())->process($container);
    }

    public function testThrowsOnNonWildcardEntryMissingSlug(): void
    {
        $container = $this->containerWith([['origin' => 'https://acme.app.example.com', 'slug' => null]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires an explicit slug');

        (new OriginHeaderResolverConfigPass())->process($container);
    }

    public function testValidMixedAllowListIsNormalized(): void
    {
        $container = $this->containerWith([
            ['origin' => 'https://acme.app.example.com', 'slug' => 'acme'],
            ['origin' => 'http://beta.dev.example.com:8080', 'slug' => 'beta'],
            ['origin' => 'https://*.app.example.com', 'slug' => null],
        ]);

        (new OriginHeaderResolverConfigPass())->process($container);

        /** @var list<array<string, mixed>> $normalized */
        $normalized = $container->getParameter('tenancy.origin.allow_list');

        $this->assertCount(3, $normalized);

        $this->assertSame('https://acme.app.example.com:443', $normalized[0]['origin']);
        $this->assertSame('acme.app.example.com', $normalized[0]['host']);
        $this->assertSame('https', $normalized[0]['scheme']);
        $this->assertSame(443, $normalized[0]['port']);
        $this->assertFalse($normalized[0]['is_wildcard']);
        $this->assertNull($normalized[0]['wildcard_suffix']);
        $this->assertSame('acme', $normalized[0]['slug']);

        $this->assertSame('http://beta.dev.example.com:8080', $normalized[1]['origin']);
        $this->assertSame(8080, $normalized[1]['port']);
        $this->assertSame('http', $normalized[1]['scheme']);
        $this->assertFalse($normalized[1]['is_wildcard']);

        $this->assertTrue($normalized[2]['is_wildcard']);
        $this->assertSame('.app.example.com', $normalized[2]['wildcard_suffix']);
        $this->assertSame(443, $normalized[2]['port']);
        $this->assertNull($normalized[2]['slug']);
    }

    /**
     * @param list<array<string, mixed>> $allowList
     */
    private function containerWith(array $allowList): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('tenancy.resolvers', ['host', 'origin']);
        $container->setParameter('tenancy.origin.allow_list', $allowList);

        return $container;
    }
}
