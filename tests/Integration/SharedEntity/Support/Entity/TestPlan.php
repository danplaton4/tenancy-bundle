<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\SharedEntity\Support\Entity;

// Shared attribute lands in Plan 25-01
use Doctrine\ORM\Mapping as ORM;

#[\Tenancy\Bundle\Attribute\Shared]
#[ORM\Entity]
#[ORM\Table(name: 'test_plans')]
class TestPlan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'integer')]
    private int $priceCents;

    public function __construct(string $name, int $priceCents = 0)
    {
        $this->name = $name;
        $this->priceCents = $priceCents;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getPriceCents(): int
    {
        return $this->priceCents;
    }

    public function setPriceCents(int $priceCents): self
    {
        $this->priceCents = $priceCents;

        return $this;
    }
}
