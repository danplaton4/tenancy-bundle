<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Default concrete tenant entity, used when `tenancy.tenant_entity_class`
 * is left at its default value. All fields, getters, setters, and
 * lifecycle callbacks live in {@see AbstractTenant} so that downstream
 * applications can extend AbstractTenant without colliding with this
 * concrete entity on the `tenancy_tenants` table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tenancy_tenants')]
class Tenant extends AbstractTenant
{
}
