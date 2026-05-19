<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Mailer;

/**
 * Redacts the password component of a DSN string.
 *
 * Single source of truth — used by SanitizingMailerDecorator (catch block) and
 * TenantDataCollector (profiler panel, Plan 04). Regex covers `smtp://`,
 * `smtps://`, `sendmail://` and any other scheme that follows the
 * `<scheme>://<user>:<password>@<host>` shape.
 *
 * @see .planning/phases/20-mailer-bootstrapper/20-CONTEXT.md D-06 (specification)
 */
final class DsnSanitizer
{
    public const REDACTION_REGEX = '/(:[\/]{0,2}[^:]+:)[^@]+(@)/';
    public const REPLACEMENT = '$1***$2';

    public static function redact(?string $dsn): ?string
    {
        if (null === $dsn || '' === $dsn) {
            return $dsn;
        }

        return preg_replace(self::REDACTION_REGEX, self::REPLACEMENT, $dsn) ?? $dsn;
    }
}
