<?php

declare(strict_types=1);

namespace App\Entity\Landlord;

use Doctrine\ORM\Mapping as ORM;
use Tenancy\Bundle\Entity\Tenant;

#[ORM\Entity]
#[ORM\Table(name: 'tenancy_tenants')]
class DemoTenant extends Tenant
{
    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    private ?string $brandColor = null;

    public function getBrandColor(): ?string
    {
        return $this->brandColor;
    }

    public function setBrandColor(?string $c): self
    {
        $this->brandColor = $c;

        return $this;
    }
}
