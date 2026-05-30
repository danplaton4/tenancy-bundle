<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Exception;

/**
 * Thrown when `AdapterDsnParser` encounters a scheme it does not recognise
 * while parsing a tenant's `filesystemConfig.adapter_dsn`.
 *
 * Extends \LogicException (not \RuntimeException) so Symfony Messenger's
 * default retry strategy does NOT re-queue the misconfigured worker:
 * an unknown DSN scheme is a programmer/operator error, not a transient fault.
 *
 * Sibling exception of MissingFilesystemConfigException (per
 * .planning/phases/24-filesystem-bootstrapper/24-RESEARCH.md §Q3): the two
 * failures share a base class (\LogicException) for retry semantics but stay
 * distinct types so callers can distinguish "set the column" from
 * "extend the parser".
 *
 * @see .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-EXCEPTION
 */
final class UnsupportedAdapterDsnSchemeException extends \LogicException
{
    public static function forScheme(string $scheme, string $supportedList): self
    {
        return new self(sprintf(
            'tenancy: AdapterDsnParser does not support scheme "%s://" (supported: %s). Extend AdapterDsnParser to register additional schemes — see docs/user-guide/filesystem-bootstrapper.md.',
            $scheme,
            $supportedList
        ));
    }
}
