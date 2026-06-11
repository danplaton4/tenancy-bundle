<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\SharedEntity\Support\Entity;

// Shared attribute lands in Plan 25-01
use Doctrine\ORM\Mapping as ORM;

/**
 * #[Shared] entity with a ManyToOne association — used by SHARE-01-m to verify
 * that association fields are NOT fanned out (only scalar fields are copied).
 *
 * The `category` association is intentionally left as NULL on tenant copies per
 * the one-level cascade boundary (DEC-SHARE-02).
 */
#[\Tenancy\Bundle\Attribute\Shared]
#[ORM\Entity]
#[ORM\Table(name: 'test_plans_with_assoc')]
class TestPlanWithAssociation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    #[ORM\ManyToOne(targetEntity: TestPlanCategory::class)]
    private ?TestPlanCategory $category = null;

    public function __construct(string $title)
    {
        $this->title = $title;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getCategory(): ?TestPlanCategory
    {
        return $this->category;
    }

    public function setCategory(?TestPlanCategory $category): self
    {
        $this->category = $category;

        return $this;
    }
}
