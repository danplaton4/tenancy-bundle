<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Message;

/**
 * Lightweight async message value object for shared-entity fan-out (SHARE-03).
 *
 * Carries ONLY scalar identifiers — never the full entity object. This is deliberate:
 *  - The handler re-fetches the LATEST landlord state at handle time (D-05), so stale
 *    entity snapshots in the message payload are both wasteful and dangerous.
 *  - If the landlord row has been deleted by handle time, the handler treats the
 *    vanished row as a tenant-side delete and propagates the removal (D-04).
 *  - Dead-letter inspection reveals only class name + PK — never row data (T-27-01-DLQ).
 *
 * @see SharedEntityChangedMessage — 27-CONTEXT.md §D-04, §D-05
 */
final class SharedEntityChangedMessage
{
    /**
     * @param class-string               $entityClass the fully-qualified class name of the shared entity
     * @param array<string, mixed>       $identifier  Scalar PK values (pre-captured in onFlush for deletes,
     *                                                from getIdentifierValues() for insert/update).
     *                                                Must contain only scalar values — no entity objects.
     * @param 'insert'|'update'|'delete' $changeType  the type of change that triggered this message
     */
    public function __construct(
        public readonly string $entityClass,
        public readonly array $identifier,
        public readonly string $changeType,
    ) {
    }
}
