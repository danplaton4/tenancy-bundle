<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Messenger\Support;

/**
 * No-op handler for the bare \stdClass probe messages the middleware integration
 * tests dispatch.
 *
 * The tests only assert that TenantSendingMiddleware attaches (or omits) a
 * TenantStamp on dispatch — the message body is irrelevant. Earlier the test bus
 * relied on `default_middleware: allow_no_handlers` to tolerate the handler-less
 * probe, but Symfony 8.1 no longer applies that option to the default bus as the
 * older releases did, so a handler-less dispatch throws NoHandlerForMessageException.
 * Registering an explicit handler keeps the probe valid on every supported Symfony
 * version (7.4 / 8.0 / 8.1) without depending on the lenient-bus behaviour.
 */
final class NoopMessageHandler
{
    public function __invoke(\stdClass $message): void
    {
    }
}
