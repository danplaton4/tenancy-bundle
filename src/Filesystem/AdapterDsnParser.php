<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Filesystem;

use League\Flysystem\FilesystemAdapter;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\Visibility;

/**
 * Parses a tenant `filesystemConfig.adapter_dsn` string into a
 * {@see FilesystemAdapter} instance.
 *
 * Flysystem 3 ships YAML/PHP-config-based adapter construction inside
 * `league/flysystem-bundle`, but exposes NO standalone DSN-string parser
 * (verified .planning/phases/24-filesystem-bootstrapper/24-RESEARCH.md §Don't
 * Hand-Roll §Adapter construction). This minimal parser bridges the gap for
 * `per_tenant_adapter` mode — three schemes cover the v0.4 demand surface:
 *
 *   - `local:///srv/uploads[?write_flags=N]`  → LocalFilesystemAdapter
 *   - `memory://[?visibility=public|private]` → InMemoryFilesystemAdapter
 *   - `s3:///bucket?region=…&key=…&secret=…` → AwsS3V3Adapter
 *     (requires `league/flysystem-aws-s3-v3` + `aws/aws-sdk-php`; raises
 *     `\Tenancy\Bundle\Exception\UnsupportedAdapterDsnSchemeException` when
 *     absent — same optional-dep discipline as the rest of the bundle)
 *
 * Schemes are stored in a closure registry, so downstream phases AND
 * downstream users can extend the parser without modifying core code:
 *
 * ```php
 * $parser->addScheme('azure', static fn (string $dsn): FilesystemAdapter
 *     => new \League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter(…));
 * ```
 *
 * Unknown schemes raise
 * `\Tenancy\Bundle\Exception\UnsupportedAdapterDsnSchemeException` (extends
 * `\LogicException`, mirroring the Phase 23 WR-01 Messenger no-retry pattern
 * per .planning/phases/24-filesystem-bootstrapper/24-RESEARCH.md §Open
 * Questions Q3). Malformed strings without a scheme are routed to the same
 * unknown-scheme branch — a programmer/config error, not a transient fault.
 *
 * The dedicated `UnsupportedAdapterDsnSchemeException` class is owned by
 * sibling Plan 24-02; when it has not landed yet at parser-build time the
 * runtime fallback throws a plain `\LogicException` with an identically
 * shaped message — preserving the Messenger no-retry contract via the
 * shared ancestry.
 *
 * ### Why a hand-rolled scheme matcher (not parse_url)
 *
 * PHP's `parse_url()` returns `false` for many `<scheme>://...` strings whose
 * scheme is not on its built-in allow-list (e.g. `local:///srv/uploads`,
 * `s3:///bucket?…`). We therefore extract the scheme with a strict
 * RFC-3986-compatible regex and split the remainder manually. This matches
 * the behaviour Flysystem itself relies on (its adapters parse paths via
 * `League\Flysystem\PathPrefixer`, not via `parse_url`).
 *
 * ### Trust boundary (RESEARCH.md §Pitfall 5)
 *
 * The DSN is admin-supplied (set on `AbstractTenant.filesystemConfig.adapter_dsn`)
 * and treated as TRUSTED. Application-level path-traversal sanitisation for
 * arguments passed to the resulting adapter (e.g. `$fs->read('../etc/passwd')`)
 * is OUT OF SCOPE for the bundle — the application is responsible for that
 * surface. See `docs/user-guide/filesystem-bootstrapper.md` (Plan 24-09).
 *
 * ### Credential-leak discipline (T-24-04-01)
 *
 * The `s3://` builder MUST NEVER echo the access key or secret into an
 * exception message. The `UnsupportedAdapterDsnSchemeException` factory is
 * called with the SCHEME only, never the full DSN string.
 */
final class AdapterDsnParser
{
    /**
     * RFC 3986 §3.1 — scheme must start with an alpha and contain only
     * alpha/digit/`+`/`-`/`.`. The `://` separator is required by the
     * three default schemes we ship; an "opaque" URI like `mailto:x@y` is
     * not a Flysystem adapter shape, so we don't try to match it.
     */
    private const SCHEME_PATTERN = '/^([a-zA-Z][a-zA-Z0-9+\-.]*):\/\/(.*)$/';

    /**
     * @var array<string, \Closure(string): FilesystemAdapter>
     */
    private array $schemes = [];

    public function __construct()
    {
        $this->addScheme('local', $this->localBuilder());
        $this->addScheme('memory', $this->memoryBuilder());
        $this->addScheme('s3', $this->s3Builder());
    }

    /**
     * Register (or overwrite) a scheme handler.
     *
     * The scheme key is normalised to lower-case so `parse('SCHEME://')` and
     * `parse('scheme://')` both resolve to the same handler.
     *
     * @param \Closure(string): FilesystemAdapter $factory
     */
    public function addScheme(string $scheme, \Closure $factory): void
    {
        $this->schemes[strtolower($scheme)] = $factory;
    }

    /**
     * Lower-case scheme strings in insertion order.
     *
     * @return list<string>
     */
    public function supportedSchemes(): array
    {
        return array_keys($this->schemes);
    }

    /**
     * Parse a DSN string into a FilesystemAdapter instance.
     *
     * @throws \LogicException (specifically
     *                         {@see \Tenancy\Bundle\Exception\UnsupportedAdapterDsnSchemeException}
     *                         when Plan 24-02 has landed; a plain LogicException
     *                         with the same message shape during the transition
     *                         window) when the DSN's scheme is not registered,
     *                         the DSN has no scheme at all, or the DSN is empty
     */
    public function parse(string $dsn): FilesystemAdapter
    {
        if ('' === $dsn || !preg_match(self::SCHEME_PATTERN, $dsn, $matches)) {
            throw $this->unsupportedScheme('(none)');
        }

        $scheme = strtolower($matches[1]);

        if (!isset($this->schemes[$scheme])) {
            throw $this->unsupportedScheme($scheme);
        }

        return ($this->schemes[$scheme])($dsn);
    }

    /**
     * Construct the unsupported-scheme exception, preferring the dedicated
     * class from Plan 24-02 when available, falling back to a plain
     * `\LogicException` (still LogicException-derived → Messenger no-retry
     * contract preserved).
     *
     * The exception message echoes ONLY the scheme name and the list of
     * supported schemes — never the full DSN — to honor the credential-leak
     * invariant T-24-04-01.
     */
    private function unsupportedScheme(string $scheme): \LogicException
    {
        $supported = implode(' ', $this->supportedSchemes());

        // The dedicated class lives in `src/Exception/` and is owned by Plan
        // 24-02. While that plan is in flight (Wave 1, parallel) the class may
        // not yet exist at parse-time; we degrade gracefully to a plain
        // \LogicException with the same message shape so the Messenger no-retry
        // contract is preserved across the entire transition window.
        $dedicatedClass = 'Tenancy\\Bundle\\Exception\\UnsupportedAdapterDsnSchemeException';

        if (class_exists($dedicatedClass)) {
            /** @var callable(string, string): \LogicException $factory */
            $factory = [$dedicatedClass, 'forScheme'];

            return $factory($scheme, $supported);
        }

        return new \LogicException(sprintf(
            'tenancy: AdapterDsnParser does not support scheme "%s://" (supported: %s). Extend AdapterDsnParser to register additional schemes — see docs/user-guide/filesystem-bootstrapper.md.',
            $scheme,
            $supported
        ));
    }

    /**
     * Split a `scheme://rest` DSN into `[path, query]`. Neither may be null —
     * an absent query is the empty string.
     *
     * @return array{0: string, 1: string}
     */
    private static function splitPathQuery(string $dsn): array
    {
        if (!preg_match(self::SCHEME_PATTERN, $dsn, $matches)) {
            return ['', ''];
        }

        $rest = $matches[2];
        $query = '';
        if (str_contains($rest, '?')) {
            [$rest, $query] = explode('?', $rest, 2);
        }

        return [$rest, $query];
    }

    /**
     * @return array<string, string>
     */
    private static function parseQuery(string $query): array
    {
        if ('' === $query) {
            return [];
        }
        $out = [];
        parse_str($query, $out);

        // parse_str produces array<string, mixed>; coerce scalars to string for
        // strict PHPStan-level-9 typing — DSN query values are always scalar.
        // Array-style keys (e.g. write_flags[]=2) indicate a mis-formed DSN
        // query: throw naming only the key (never the value) to preserve the
        // credential-leak discipline (T-24-04-01).
        $result = [];
        foreach ($out as $k => $v) {
            if (!is_scalar($v)) {
                throw new \InvalidArgumentException(sprintf('DSN query parameter "%s" produced a non-scalar value (array-style syntax is not supported). Use a scalar value instead.', (string) $k));
            }
            $result[(string) $k] = (string) $v;
        }

        return $result;
    }

    /**
     * @return \Closure(string): FilesystemAdapter
     */
    private function localBuilder(): \Closure
    {
        return static function (string $dsn): FilesystemAdapter {
            [$path, $query] = self::splitPathQuery($dsn);
            if ('' === $path) {
                throw new \InvalidArgumentException('local:// DSN must include a path (e.g. local:///srv/uploads).');
            }

            $writeFlags = \LOCK_EX;
            $params = self::parseQuery($query);
            if (isset($params['write_flags']) && is_numeric($params['write_flags'])) {
                $writeFlags = (int) $params['write_flags'];
            }

            return new LocalFilesystemAdapter($path, null, $writeFlags);
        };
    }

    /**
     * @return \Closure(string): FilesystemAdapter
     */
    private function memoryBuilder(): \Closure
    {
        return static function (string $dsn): FilesystemAdapter {
            $visibility = Visibility::PUBLIC;
            [, $query] = self::splitPathQuery($dsn);
            $params = self::parseQuery($query);
            if (isset($params['visibility']) && 'private' === $params['visibility']) {
                $visibility = Visibility::PRIVATE;
            }

            return new InMemoryFilesystemAdapter($visibility);
        };
    }

    /**
     * @return \Closure(string): FilesystemAdapter
     */
    private function s3Builder(): \Closure
    {
        $unsupportedFactory = function (): \LogicException {
            return $this->unsupportedScheme('s3');
        };

        return static function (string $dsn) use ($unsupportedFactory): FilesystemAdapter {
            // Optional-dep guard: gracefully degrade when the AWS SDK or the
            // Flysystem S3 adapter package is absent. Critical: we pass only
            // the scheme to the exception factory — never the DSN string
            // itself, which may contain `key=` / `secret=` in the query.
            if (
                !class_exists('League\\Flysystem\\AwsS3V3\\AwsS3V3Adapter')
                || !class_exists('Aws\\S3\\S3Client')
            ) {
                throw $unsupportedFactory();
            }

            [$path, $query] = self::splitPathQuery($dsn);
            $bucket = ltrim($path, '/');
            if ('' === $bucket) {
                // Bucket missing — programmer error. No credentials echoed:
                // the message names only the missing structural piece.
                throw new \InvalidArgumentException('s3:// DSN must include a bucket path (e.g. s3:///my-bucket).');
            }

            $params = self::parseQuery($query);
            $region = $params['region'] ?? 'us-east-1';
            $clientConfig = [
                'version' => 'latest',
                'region' => $region,
            ];
            if (isset($params['key'], $params['secret'])) {
                $clientConfig['credentials'] = [
                    'key' => $params['key'],
                    'secret' => $params['secret'],
                ];
            }
            if (isset($params['endpoint'])) {
                $clientConfig['endpoint'] = $params['endpoint'];
            }
            if (isset($params['use_path_style_endpoint'])) {
                $clientConfig['use_path_style_endpoint'] = filter_var(
                    $params['use_path_style_endpoint'],
                    \FILTER_VALIDATE_BOOLEAN
                );
            }

            // FQCN strings keep PHPStan + cs-fixer quiet when the optional
            // AWS SDK is absent at analysis time (PHPStan is configured for
            // `src/` only — this guard avoids a `class.notFound` baseline
            // entry for the bundle install footprint without AWS).
            $s3ClientClass = 'Aws\\S3\\S3Client';
            $awsAdapterClass = 'League\\Flysystem\\AwsS3V3\\AwsS3V3Adapter';

            /** @var FilesystemAdapter $adapter */
            $adapter = new $awsAdapterClass(new $s3ClientClass($clientConfig), $bucket);

            return $adapter;
        };
    }
}
