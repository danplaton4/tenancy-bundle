<?php

declare(strict_types=1);

namespace Tenancy\Bundle;

interface TenantInterface
{
    public function getSlug(): string;

    public function getDomain(): ?string;

    /** @return array<string, mixed> */
    public function getConnectionConfig(): array;

    public function getName(): string;

    public function isActive(): bool;
}
