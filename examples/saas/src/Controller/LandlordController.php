<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Landlord\DemoTenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Tenancy\Bundle\Context\TenantContext;

class LandlordController extends AbstractController
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        private readonly EntityManagerInterface $landlordEntityManager,
    ) {
    }

    #[Route('/', name: 'landlord_index', host: 'tenancy.localhost')]
    public function index(TenantContext $tenantContext): Response
    {
        // Defensive guard: this route is host-constrained to tenancy.localhost (no tenant subdomain).
        // The ResolverChain returns null for the apex; if somehow a tenant was resolved, return 404.
        if ($tenantContext->hasTenant()) {
            throw $this->createNotFoundException('Unexpected tenant resolved on landlord domain.');
        }

        $tenants = $this->landlordEntityManager->getRepository(DemoTenant::class)->findAll();

        return $this->render('landlord/index.html.twig', ['tenants' => $tenants]);
    }
}
