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
 * @see .planning/phases/20-mailer-bootstrapper/20-REVIEW.md WR-07 (regex tightening rationale)
 * @see .planning/phases/20-mailer-bootstrapper/20-10-PLAN.md
 */
final class DsnSanitizer
{
    // Tightened in Plan 20-10 (REVIEW WR-07): require the literal `://` DSN
    // shape so free-text colons (e.g., "smtp:587 timeout") do not match.
    // Covers smtp://, smtps://, sendmail://, and any other scheme of the
    // form <scheme>://[<user>]:<password>@<host>[/<path>]. Composite DSNs
    // like failover(smtp://u:p@h1 smtp://u:p@h2) work because the `(@)` anchor
    // terminates each match before the next `://` segment.
    //
    // Widened in Plan 33-05 (REVIEW CR-01): the username group is optional
    // (`[^:/@]*`) so password-only DSNs (e.g., `redis://:pw@host`, Redis AUTH)
    // are redacted, and the password group is `[^@]+` (terminated only by `@`)
    // so passwords containing `/` (valid for MySQL/Postgres) do not leak.
    public const REDACTION_REGEX = '#(://[^:/@]*:)[^@]+(@)#';
    public const REPLACEMENT = '$1***$2';

    public static function redact(?string $dsn): ?string
    {
        if (null === $dsn || '' === $dsn) {
            return $dsn;
        }

        return preg_replace(self::REDACTION_REGEX, self::REPLACEMENT, $dsn) ?? $dsn;
    }
}
