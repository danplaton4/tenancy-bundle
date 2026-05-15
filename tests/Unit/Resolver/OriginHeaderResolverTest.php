<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Resolver;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\Exception\TenantInactiveException;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\Resolver\OriginHeaderResolver;
use Tenancy\Bundle\TenantInterface;
use Tenancy\Bundle\Tests\Unit\Resolver\Support\RecordingLogger;

final class OriginHeaderResolverTest extends TestCase
{
    private TenantProviderInterface&MockObject $provider;
    private RecordingLogger $logger;
    private OriginHeaderResolver $resolver;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(TenantProviderInterface::class);
        $this->logger = new RecordingLogger();
        $this->resolver = new OriginHeaderResolver(
            $this->provider,
            $this->logger,
            $this->allowList(),
        );
    }

    public function testReturnsNullWhenOriginHeaderAbsent(): void
    {
        $this->provider->expects($this->never())->method('findBySlug');

        $request = Request::create('/');
        $this->assertNull($this->resolver->resolve($request));
    }

    public function testReturnsNullWhenOriginHeaderEmpty(): void
    {
        $this->provider->expects($this->never())->method('findBySlug');

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ORIGIN' => '']);
        $this->assertNull($this->resolver->resolve($request));
    }

    public function testReturnsNullOnOptionsPreflightEvenWhenOriginPresent(): void
    {
        $this->provider->expects($this->never())->method('findBySlug');

        $request = Request::create('/', 'OPTIONS', [], [], [], ['HTTP_ORIGIN' => 'https://acme.app.example.com']);
        $this->assertNull($this->resolver->resolve($request));
    }

    public function testExactAllowListEntryResolvesTenant(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');

        $this->provider->expects($this->once())
            ->method('findBySlug')
            ->with('acme')
            ->willReturn($tenant);

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ORIGIN' => 'https://acme.app.example.com']);
        $this->assertSame($tenant, $this->resolver->resolve($request));
    }

    public function testWildcardAllowListEntryResolvesViaLeftmostLabel(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('beta');

        $this->provider->expects($this->once())
            ->method('findBySlug')
            ->with('beta')
            ->willReturn($tenant);

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ORIGIN' => 'https://beta.app.example.com']);
        $this->assertSame($tenant, $this->resolver->resolve($request));
    }

    public function testNonMatchingOriginReturnsNull(): void
    {
        $this->provider->expects($this->never())->method('findBySlug');

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ORIGIN' => 'https://evil.example.org']);
        $this->assertNull($this->resolver->resolve($request));
    }

    public function testReturnsNullOnUnparseableOrigin(): void
    {
        $this->provider->expects($this->never())->method('findBySlug');

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ORIGIN' => 'not a url']);
        $this->assertNull($this->resolver->resolve($request));
    }

    public function testReturnsNullWhenProviderThrowsNotFound(): void
    {
        $this->provider->expects($this->once())
            ->method('findBySlug')
            ->with('acme')
            ->willThrowException(new TenantNotFoundException('Tenant "acme" not found.'));

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ORIGIN' => 'https://acme.app.example.com']);
        $this->assertNull($this->resolver->resolve($request));
    }

    public function testBubblesInactiveException(): void
    {
        $this->provider->expects($this->once())
            ->method('findBySlug')
            ->with('acme')
            ->willThrowException(new TenantInactiveException('acme'));

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ORIGIN' => 'https://acme.app.example.com']);

        $this->expectException(TenantInactiveException::class);
        $this->resolver->resolve($request);
    }

    public function testMismatchWithXTenantIdLogsWarningAtWarningLevelWithStructuredContext(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');

        $this->provider->expects($this->once())
            ->method('findBySlug')
            ->with('acme')
            ->willReturn($tenant);

        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_ORIGIN' => 'https://acme.app.example.com',
            'HTTP_X_TENANT_ID' => 'beta',
        ]);

        $this->assertSame($tenant, $this->resolver->resolve($request));

        $warnings = $this->logger->warnings();
        $this->assertCount(1, $warnings);
        $this->assertSame('warning', $warnings[0]['level']);
        $this->assertSame([
            'origin' => 'https://acme.app.example.com',
            'origin_slug' => 'acme',
            'header_slug' => 'beta',
            'winner' => 'origin',
        ], $warnings[0]['context']);
    }

    /**
     * @return list<array{
     *     origin: string, host: string, scheme: string, port: int,
     *     is_wildcard: bool, wildcard_suffix: ?string, slug: ?string
     * }>
     */
    private function allowList(): array
    {
        return [
            [
                'origin' => 'https://acme.app.example.com:443',
                'host' => 'acme.app.example.com',
                'scheme' => 'https',
                'port' => 443,
                'is_wildcard' => false,
                'wildcard_suffix' => null,
                'slug' => 'acme',
            ],
            [
                'origin' => 'https://*.app.example.com:443',
                'host' => '*.app.example.com',
                'scheme' => 'https',
                'port' => 443,
                'is_wildcard' => true,
                'wildcard_suffix' => '.app.example.com',
                'slug' => null,
            ],
        ];
    }
}
