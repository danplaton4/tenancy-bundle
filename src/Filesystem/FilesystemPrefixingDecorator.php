<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Filesystem;

use League\Flysystem\DirectoryAttributes;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\PathPrefixer;
use Tenancy\Bundle\Context\TenantContext;

/**
 * Prefix-mode FilesystemOperator decorator.
 *
 * On every method call, reads the active tenant from TenantContext LIVE (never
 * caches the prefix in instance state — see CONTEXT.md §Anti-Patterns: "must
 * read TenantContext LIVE on every call" and RESEARCH.md §Pitfall 4: decorator
 * instance state leaks across tenants in long-running workers).
 *
 * Per RESEARCH.md §Pattern 1: when no tenant is active, the decorator passes
 * through with an empty prefix — "no tenant = no scoping" is the intended
 * semantic for prefix mode.
 *
 * Per RESEARCH.md §Open Questions Q1 (RESOLVED): listContents() strips the
 * tenant prefix from returned StorageAttributes paths so application code
 * always sees tenant-relative paths.
 *
 * Per RESEARCH.md §Open Questions Q2 (RESOLVED/ACCEPT): publicUrl(),
 * temporaryUrl(), and checksum() forward the prefixed path to the inner
 * operator unchanged. Users wanting URL-level tenant hiding should use
 * per_tenant_adapter mode instead.
 *
 * @security path-traversal
 * Path arguments are forwarded VERBATIM to the inner adapter after the tenant
 * prefix is prepended. The decorator does NOT sanitise `../` traversal attempts
 * — this is the application's responsibility (see RESEARCH.md §Pitfall 5).
 * Sanitise all user-supplied path arguments before passing them to any
 * FilesystemOperator method. The decorator's trust boundary is
 * application-code → decorator, not end-user → decorator.
 *
 * @see TenantAwareFilesystemDecorator per-tenant-adapter mode
 */
final class FilesystemPrefixingDecorator implements FilesystemOperator
{
    public function __construct(
        private readonly FilesystemOperator $inner,
        private readonly TenantContext $context,
        private readonly string $prefixTemplate = 'tenant_{slug}/',
    ) {
    }

    public function fileExists(string $location): bool
    {
        return $this->inner->fileExists($this->prefixer()->prefixPath($location));
    }

    public function directoryExists(string $location): bool
    {
        return $this->inner->directoryExists($this->prefixer()->prefixPath($location));
    }

    public function has(string $location): bool
    {
        return $this->inner->has($this->prefixer()->prefixPath($location));
    }

    public function read(string $location): string
    {
        return $this->inner->read($this->prefixer()->prefixPath($location));
    }

    public function readStream(string $location)
    {
        return $this->inner->readStream($this->prefixer()->prefixPath($location));
    }

    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
    {
        $prefixer = $this->prefixer();
        $prefixedLocation = $prefixer->prefixPath($location);
        $inner = $this->inner;

        return new DirectoryListing(
            (static function () use ($prefixer, $prefixedLocation, $deep, $inner): \Generator {
                foreach ($inner->listContents($prefixedLocation, $deep) as $entry) {
                    if ($entry instanceof FileAttributes) {
                        yield new FileAttributes(
                            $prefixer->stripPrefix($entry->path()),
                            $entry->fileSize(),
                            $entry->visibility(),
                            $entry->lastModified(),
                            $entry->mimeType(),
                            $entry->extraMetadata(),
                        );
                    } else {
                        /* @var DirectoryAttributes $entry */
                        yield new DirectoryAttributes(
                            $prefixer->stripDirectoryPrefix($entry->path()),
                            $entry->visibility(),
                            $entry->lastModified(),
                            $entry->extraMetadata(),
                        );
                    }
                }
            })(),
        );
    }

    public function lastModified(string $path): int
    {
        return $this->inner->lastModified($this->prefixer()->prefixPath($path));
    }

    public function fileSize(string $path): int
    {
        return $this->inner->fileSize($this->prefixer()->prefixPath($path));
    }

    public function mimeType(string $path): string
    {
        return $this->inner->mimeType($this->prefixer()->prefixPath($path));
    }

    public function visibility(string $path): string
    {
        return $this->inner->visibility($this->prefixer()->prefixPath($path));
    }

    /**
     * Forwards the prefixed path to the inner operator.
     *
     * Per RESEARCH.md Q2 (ACCEPT): publicUrl() exposes the tenant prefix in
     * the URL — this is an inherent property of prefix mode. Users wanting
     * URL-level tenant hiding should use per_tenant_adapter mode.
     *
     * @param array<mixed> $config
     */
    public function publicUrl(string $path, array $config = []): string
    {
        return $this->inner->publicUrl($this->prefixer()->prefixPath($path), $config);
    }

    /**
     * Forwards the prefixed path to the inner operator.
     *
     * Per RESEARCH.md Q2 (ACCEPT): temporaryUrl() exposes the tenant prefix
     * in the URL — this is an inherent property of prefix mode.
     *
     * @param array<mixed> $config
     */
    public function temporaryUrl(string $path, \DateTimeInterface $expiresAt, array $config = []): string
    {
        return $this->inner->temporaryUrl($this->prefixer()->prefixPath($path), $expiresAt, $config);
    }

    /**
     * Forwards the prefixed path to the inner operator.
     *
     * Per RESEARCH.md Q2 (ACCEPT): checksum is computed on the inner path
     * which includes the tenant prefix — consistent with the stored data.
     *
     * @param array<mixed> $config
     */
    public function checksum(string $path, array $config = []): string
    {
        return $this->inner->checksum($this->prefixer()->prefixPath($path), $config);
    }

    /**
     * @param array<mixed> $config
     */
    public function write(string $location, string $contents, array $config = []): void
    {
        $this->inner->write($this->prefixer()->prefixPath($location), $contents, $config);
    }

    /**
     * @param array<mixed> $config
     */
    public function writeStream(string $location, $contents, array $config = []): void
    {
        $this->inner->writeStream($this->prefixer()->prefixPath($location), $contents, $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->inner->setVisibility($this->prefixer()->prefixPath($path), $visibility);
    }

    public function delete(string $location): void
    {
        $this->inner->delete($this->prefixer()->prefixPath($location));
    }

    public function deleteDirectory(string $location): void
    {
        $this->inner->deleteDirectory($this->prefixer()->prefixPath($location));
    }

    /**
     * @param array<mixed> $config
     */
    public function createDirectory(string $location, array $config = []): void
    {
        $this->inner->createDirectory($this->prefixer()->prefixPath($location), $config);
    }

    /**
     * @param array<mixed> $config
     */
    public function move(string $source, string $destination, array $config = []): void
    {
        $prefixer = $this->prefixer();
        $this->inner->move(
            $prefixer->prefixPath($source),
            $prefixer->prefixPath($destination),
            $config,
        );
    }

    /**
     * @param array<mixed> $config
     */
    public function copy(string $source, string $destination, array $config = []): void
    {
        $prefixer = $this->prefixer();
        $this->inner->copy(
            $prefixer->prefixPath($source),
            $prefixer->prefixPath($destination),
            $config,
        );
    }

    /**
     * Builds a fresh PathPrefixer derived from the currently-active tenant.
     *
     * This method is the ONLY place TenantContext is read. Every public method
     * calls it fresh per invocation — never cache the result in instance state
     * (see RESEARCH.md §Pitfall 4 and CONTEXT.md §Anti-Patterns to Guard
     * Against: cross-tenant data leak via shared decorator instance).
     */
    private function prefixer(): PathPrefixer
    {
        $tenant = $this->context->getTenant();

        if (null === $tenant) {
            return new PathPrefixer('');
        }

        $prefix = str_replace('{slug}', $tenant->getSlug(), $this->prefixTemplate);

        if ('' !== $prefix && !str_ends_with($prefix, '/')) {
            $prefix .= '/';
        }

        return new PathPrefixer($prefix);
    }
}
