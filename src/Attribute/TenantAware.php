<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Attribute;

/**
 * Marks a Doctrine entity for automatic tenant scoping via SQL filter.
 *
 * Add a `tenant_id VARCHAR(63)` column to your entity. The SQL filter
 * injects the active tenant's slug automatically.
 *
 * In inheritance hierarchies (STI/JTI), place this attribute on the
 * root entity — Doctrine's addFilterConstraint receives parent metadata.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class TenantAware {}
