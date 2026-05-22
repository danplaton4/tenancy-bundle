<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Tenancy\Bundle\Context\TenantContext;

/**
 * Deliberately authn-free for the demo. Remove from any non-local deployment.
 *
 * The /_demo/send-test-mail route is the ONE write path in the demo (CONTEXT D-09).
 * It dispatches a test email via MailerInterface; From/Reply-To are injected by
 * Phase 20 TenantMessageDecorator, which reads the active tenant's mailerFrom/mailerReplyTo.
 * No CSRF protection — localhost-only, documented in README (T-21-DM: accept).
 */
class DemoMailController extends AbstractController
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/_demo/send-test-mail', name: 'demo_send_mail', methods: ['POST'])]
    public function send(): Response
    {
        $tenant = $this->tenantContext->getTenant();
        // From + Reply-To injected by Phase 20 TenantMessageDecorator — controller is tenant-agnostic.
        $this->mailer->send(
            (new Email())
                ->to('demo@example.test')
                ->subject(sprintf('Test from %s', $tenant?->getName() ?? 'landlord'))
                ->text('This email demonstrates per-tenant mailer dispatch.')
        );

        return new Response('Email queued — see http://localhost:8025', 202);
    }
}
