<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Docker healthcheck target (D-15).
 *
 * Returns 200 OK once FrankenPHP is serving requests.
 * The entrypoint runs `app:seed-demo` BEFORE exec'ing FrankenPHP, so by the time
 * this endpoint is reachable the tenant DBs, schemas, and posts are already seeded
 * (RESEARCH §"Pitfall 5" — Option 2 mitigation).
 *
 * No host constraint — called from `curl http://localhost/health` inside the container.
 */
class HealthController extends AbstractController
{
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): Response
    {
        return new Response('OK');
    }
}
