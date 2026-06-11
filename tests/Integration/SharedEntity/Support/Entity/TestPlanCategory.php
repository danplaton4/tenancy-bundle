<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\SharedEntity\Support\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Plain (non-shared) entity used as association target for TestPlanWithAssociation.
 * NOT marked #[Shared] — exists to verify the cascade depth = 1 boundary (DEC-SHARE-02).
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_plan_categories')]
class TestPlanCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $label;

    public function __construct(string $label)
    {
        $this->label = $label;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }
}
