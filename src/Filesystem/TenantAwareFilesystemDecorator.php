<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Filesystem;

use League\Flysystem\DirectoryListing;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Exception\MissingFilesystemConfigException;
use Tenancy\Bundle\TenantInterface;

/**
 * Per-tenant-adapter mode FilesystemOperator decorator (DEC-FILE-MODE opt-in).
 *
 * On every method call, reads TenantContext LIVE to determine the active tenant,
 * looks up a per-tenant FilesystemOperator instance from the LRU cache, and
 * delegates the entire call — path argument UNPREFIXED — to that instance.
 * The per-tenant adapter has its own root, so no prefix arithmetic is needed
 * (compare with FilesystemPrefixingDecorator which manipulates paths on a SHARED
 * adapter).
 *
 * Cache-miss path: reads the tenant's `getFilesystemConfig()['adapter_dsn']`,
 * parses it via AdapterDsnParser, wraps the result in a new Filesystem instance,
 * caches it in LruFilesystemCache keyed by tenant slug, and returns it.
 *
 * No-active-tenant path: passes through to $inner — identical "no tenant = no
 * scoping" semantic as FilesystemPrefixingDecorator. The bundle does NOT crash
 * when no tenant is resolved; the application receives the unscoped inner adapter.
 *
 * Missing config path: if getFilesystemConfig() is absent (no trait on entity) OR
 * returns null/missing 'adapter_dsn', raises MissingFilesystemConfigException
 * (extends \LogicException — Messenger no-retry per DEC-FILE-EXCEPTION).
 *
 * @see .planning/phases/24-filesystem-bootstrapper/24-RESEARCH.md §Architectural Responsibility Map per-tenant-adapter row
 * @see .planning/phases/24-filesystem-bootstrapper/24-RESEARCH.md §Pitfall 4 (live-read invariant)
 * @see .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-MODE
 *
 * @security path-traversal
 * Paths are forwarded VERBATIM to the per-tenant adapter. The adapter_dsn is
 * admin-supplied and TRUSTED. Application-level path-traversal sanitisation
 * (e.g. blocking `../` from user-supplied path arguments) is OUT OF SCOPE for
 * the bundle — the application is responsible. See RESEARCH.md §Pitfall 5 and
 * docs/user-guide/filesystem-bootstrapper.md (Plan 24-09).
 * @security tenant-isolation
 * Isolation is enforced at the adapter level: each tenant has its own
 * FilesystemAdapter instance rooted at adapter_dsn. There is no shared storage
 * path between tenants — cross-tenant reads/writes are structurally impossible
 * without deliberate override of adapter_dsn.
 *
 * ZERO non-readonly mutable instance state — live-read invariant enforced
 * identically to FilesystemPrefixingDecorator (Plan 24-05). Symfony workers
 * reuse the container: any mutable state would survive tenant switches.
 *
 * @see FilesystemPrefixingDecorator prefix-mode sibling
 */
final class TenantAwareFilesystemDecorator implements FilesystemOperator
{
    public function __construct(
        private readonly FilesystemOperator $inner,
        private readonly TenantContext $context,
        private readonly LruFilesystemCache $cache,
        private readonly AdapterDsnParser $parser,
    ) {
    }

    // -------------------------------------------------------------------------
    // Reader surface
    // -------------------------------------------------------------------------

    public function fileExists(string $location): bool
    {
        return $this->resolve()->fileExists($location);
    }

    public function directoryExists(string $location): bool
    {
        return $this->resolve()->directoryExists($location);
    }

    public function has(string $location): bool
    {
        return $this->resolve()->has($location);
    }

    public function read(string $location): string
    {
        return $this->resolve()->read($location);
    }

    public function readStream(string $location)
    {
        return $this->resolve()->readStream($location);
    }

    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
    {
        return $this->resolve()->listContents($location, $deep);
    }

    public function lastModified(string $path): int
    {
        return $this->resolve()->lastModified($path);
    }

    public function fileSize(string $path): int
    {
        return $this->resolve()->fileSize($path);
    }

    public function mimeType(string $path): string
    {
        return $this->resolve()->mimeType($path);
    }

    public function visibility(string $path): string
    {
        return $this->resolve()->visibility($path);
    }

    /**
     * @param array<mixed> $config
     */
    public function publicUrl(string $path, array $config = []): string
    {
        return $this->resolve()->publicUrl($path, $config);
    }

    /**
     * @param array<mixed> $config
     */
    public function temporaryUrl(string $path, \DateTimeInterface $expiresAt, array $config = []): string
    {
        return $this->resolve()->temporaryUrl($path, $expiresAt, $config);
    }

    /**
     * @param array<mixed> $config
     */
    public function checksum(string $path, array $config = []): string
    {
        return $this->resolve()->checksum($path, $config);
    }

    // -------------------------------------------------------------------------
    // Writer surface
    // -------------------------------------------------------------------------

    /**
     * @param array<mixed> $config
     */
    public function write(string $location, string $contents, array $config = []): void
    {
        $this->resolve()->write($location, $contents, $config);
    }

    /**
     * @param array<mixed> $config
     */
    public function writeStream(string $location, $contents, array $config = []): void
    {
        $this->resolve()->writeStream($location, $contents, $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->resolve()->setVisibility($path, $visibility);
    }

    public function delete(string $location): void
    {
        $this->resolve()->delete($location);
    }

    public function deleteDirectory(string $location): void
    {
        $this->resolve()->deleteDirectory($location);
    }

    /**
     * @param array<mixed> $config
     */
    public function createDirectory(string $location, array $config = []): void
    {
        $this->resolve()->createDirectory($location, $config);
    }

    /**
     * @param array<mixed> $config
     */
    public function move(string $source, string $destination, array $config = []): void
    {
        $this->resolve()->move($source, $destination, $config);
    }

    /**
     * @param array<mixed> $config
     */
    public function copy(string $source, string $destination, array $config = []): void
    {
        $this->resolve()->copy($source, $destination, $config);
    }

    // -------------------------------------------------------------------------
    // Resolution logic
    // -------------------------------------------------------------------------

    /**
     * Resolve the FilesystemOperator for the currently-active tenant.
     *
     * This is the ONLY point where TenantContext is read. Every public method
     * calls it fresh per invocation — never cache the result in instance state
     * (see RESEARCH.md §Pitfall 4 and CONTEXT.md §Anti-Patterns: cross-tenant
     * data leak via shared decorator instance).
     *
     * - null tenant    → $inner passthrough (no scoping)
     * - cache hit      → cached per-tenant Filesystem
     * - cache miss     → build-and-cache via AdapterDsnParser
     * - missing config → MissingFilesystemConfigException (LogicException)
     */
    private function resolve(): FilesystemOperator
    {
        $tenant = $this->context->getTenant();

        if (null === $tenant) {
            return $this->inner;
        }

        $cached = $this->cache->get($tenant->getSlug());

        if (null !== $cached) {
            return $cached;
        }

        return $this->buildAndCache($tenant);
    }

    /**
     * Build a per-tenant Filesystem from the tenant's adapter_dsn and cache it.
     *
     * @throws MissingFilesystemConfigException when adapter_dsn is null/empty
     */
    private function buildAndCache(TenantInterface $tenant): FilesystemOperator
    {
        $config = $this->readConfig($tenant);
        $dsn = $config['adapter_dsn'] ?? null;

        if (null === $dsn || '' === $dsn) {
            throw MissingFilesystemConfigException::forTenant($tenant->getSlug());
        }

        $adapter = $this->parser->parse($dsn);
        $fs = new Filesystem($adapter);
        $this->cache->set($tenant->getSlug(), $fs);

        return $fs;
    }

    /**
     * Read the tenant's filesystemConfig via method_exists probe.
     *
     * TenantInterface deliberately does NOT declare getFilesystemConfig()
     * (per DEC-FILE-CONFIG: optional trait, zero BC break). We must probe
     * at runtime so custom tenant entities without the trait receive the
     * MissingFilesystemConfigException rather than a fatal "call to undefined
     * method" error.
     *
     * @return array{prefix?: string, adapter_dsn?: string, services?: array<string>}|null
     */
    private function readConfig(TenantInterface $tenant): ?array
    {
        if (!method_exists($tenant, 'getFilesystemConfig')) {
            return null;
        }

        /** @var array{prefix?: string, adapter_dsn?: string, services?: array<string>}|null $cfg */
        $cfg = $tenant->getFilesystemConfig();

        return $cfg;
    }
}
