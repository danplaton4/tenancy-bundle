<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Tenant\Post;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Tenancy\Bundle\Context\TenantContext;

class TenantController extends AbstractController
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private readonly EntityManagerInterface $tenantEm,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/', name: 'tenant_index', host: '{slug}.tenancy.localhost', requirements: ['slug' => '[a-z0-9-]+'])]
    public function index(): Response
    {
        // Defensive guard: HostResolver must have resolved a tenant for this subdomain.
        // bundle strict_mode raises if resolver chain returns null; this guard is belt-and-suspenders.
        if (!$this->tenantContext->hasTenant()) {
            throw $this->createNotFoundException('No tenant resolved for this host.');
        }

        $tenant = $this->tenantContext->getTenant();
        $posts = $this->tenantEm->getRepository(Post::class)->findAll();

        return $this->render('tenant/index.html.twig', [
            'tenant' => $tenant,
            'posts' => $posts,
        ]);
    }
}
