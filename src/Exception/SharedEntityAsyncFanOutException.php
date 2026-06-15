<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Exception;

/**
 * Aggregate exception thrown by SharedEntityChangedMessageHandler after the per-tenant
 * fan-out loop when one or more tenants failed to apply the shared entity change (D-02).
 *
 * Design rationale (RESEARCH Pattern 2):
 *  - Extends \RuntimeException directly — NOT \UnrecoverableExceptionInterface.
 *  - This is DELIBERATE: being a plain \RuntimeException subclass means Messenger's
 *    retry_strategy->isRetryable() engages automatically. The message will be retried
 *    according to the transport's retry configuration rather than dead-lettered
 *    immediately on first failure.
 *  - Must NOT wrap or construct HandlerFailedException — that is Messenger's internal
 *    multi-handler aggregation mechanism and is not the correct pattern here (anti-pattern
 *    identified in 27-RESEARCH.md Pattern 2).
 */
final class SharedEntityAsyncFanOutException extends \RuntimeException
{
}
