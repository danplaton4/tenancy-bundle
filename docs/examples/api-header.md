# API Header Example

This tutorial shows how to build a REST API where clients identify their tenant via the
`X-Tenant-ID` header. Uses the shared-DB driver — all tenants share one database, with Doctrine's
SQL filter providing automatic tenant scoping.

**Scenario**: A billing API consumed by mobile apps and SPAs. Each client sends its tenant
identity in the `X-Tenant-ID` request header. The server scopes all data automatically.

---

## Step 1: Bundle Configuration

```yaml
# config/packages/tenancy.yaml
tenancy:
    driver: shared_db
    strict_mode: true            # throw on queries without active tenant (default)
    resolvers: ['header']        # only use header resolver for this API
```

Restricting `resolvers` to `['header']` disables subdomain and console resolvers for this API.
Only requests with a valid `X-Tenant-ID` header will have an active tenant context.

---

## Step 2: Tenant-Aware Entities

Mark entities that should be scoped per tenant with `#[TenantAware]`. Each entity **must** have
a `tenant_id VARCHAR(63)` column:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InvoiceRepository;
use Doctrine\ORM\Mapping as ORM;
use Tenancy\Bundle\Attribute\TenantAware;

#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\Table(name: 'invoices')]
#[TenantAware]
class Invoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 63, nullable: false)]
    private string $tenantId;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 255)]
    private string $description;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $tenantId, string $amount, string $description)
    {
        $this->tenantId = $tenantId;
        $this->amount = $amount;
        $this->description = $description;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenantId(): string { return $this->tenantId; }
    public function getAmount(): string { return $this->amount; }
    public function getDescription(): string { return $this->description; }
}
```

The `tenantId` field stores the tenant slug and must be set when creating entities.

---

## Step 3: API Controller

The controller code is identical to non-tenanted code. The SQL filter handles all scoping
transparently — no `WHERE tenant_id = ?` clauses in your queries:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Invoice;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Tenancy\Bundle\Context\TenantContext;

#[Route('/api/v1/invoices')]
final class InvoiceController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
    ) {}

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        // SQL filter automatically adds: AND i.tenant_id = 'acme'
        $invoices = $this->em->getRepository(Invoice::class)->findAll();

        return $this->json(array_map(
            fn (Invoice $i) => [
                'id'          => $i->getId(),
                'amount'      => $i->getAmount(),
                'description' => $i->getDescription(),
            ],
            $invoices,
        ));
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray();

        // Explicitly set tenantId when creating entities
        $invoice = new Invoice(
            tenantId:    $this->tenantContext->getTenant()->getSlug(),
            amount:      $data['amount'],
            description: $data['description'],
        );

        $this->em->persist($invoice);
        $this->em->flush();

        return $this->json(['id' => $invoice->getId()], Response::HTTP_CREATED);
    }
}
```

---

## Step 4: Client Usage

Clients send the `X-Tenant-ID` header with every request:

```bash
# List invoices for tenant 'acme'
curl -H "X-Tenant-ID: acme" https://api.example.com/api/v1/invoices

# Create an invoice for tenant 'demo'
curl -X POST \
     -H "X-Tenant-ID: demo" \
     -H "Content-Type: application/json" \
     -d '{"amount": "149.99", "description": "Enterprise plan"}' \
     https://api.example.com/api/v1/invoices

# Response — only 'demo' invoices returned
# {"id": 1, "amount": "149.99", "description": "Enterprise plan"}
```

The `HeaderResolver` reads `X-Tenant-ID`, looks up the tenant by slug, and sets the active
tenant context before the controller runs.

---

## Step 5: Strict Mode Protection

With `strict_mode: true` (default), requests without a valid `X-Tenant-ID` header that attempt
to query `#[TenantAware]` entities throw `TenantMissingException`:

```bash
# Missing header — returns 500 with TenantMissingException (in strict mode)
curl https://api.example.com/api/v1/invoices

# Unknown tenant slug — HeaderResolver returns null, context not set
curl -H "X-Tenant-ID: nonexistent" https://api.example.com/api/v1/invoices
```

To return a proper JSON error response instead of a 500, add an exception listener:

```php
<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Tenancy\Bundle\Exception\TenantMissingException;

#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class TenantMissingListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof TenantMissingException) {
            return;
        }

        $event->setResponse(new JsonResponse(
            ['error' => 'Tenant context required. Provide a valid X-Tenant-ID header.'],
            Response::HTTP_UNAUTHORIZED,
        ));
    }
}
```

---

## Step 6: Testing

Use `WebTestCase` to test the API with tenant headers:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class InvoiceControllerTest extends WebTestCase
{
    public function testListInvoicesRequiresTenantHeader(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/invoices');

        // strict_mode: true → TenantMissingException → our listener returns 401
        $this->assertResponseStatusCodeSame(401);
    }

    public function testListInvoicesForTenant(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/invoices', [], [], [
            'HTTP_X_TENANT_ID' => 'acme',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseFormatSame('json');

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testInvoicesAreIsolatedPerTenant(): void
    {
        $client = static::createClient();

        // Create invoice for 'acme'
        $client->request('POST', '/api/v1/invoices', [], [], [
            'HTTP_X_TENANT_ID' => 'acme',
            'CONTENT_TYPE'     => 'application/json',
        ], json_encode(['amount' => '99.99', 'description' => 'Test invoice']));

        $this->assertResponseStatusCodeSame(201);

        // List invoices for 'demo' — should not include 'acme' invoices
        $client->request('GET', '/api/v1/invoices', [], [], [
            'HTTP_X_TENANT_ID' => 'demo',
        ]);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertCount(0, $data, 'demo should not see acme invoices');
    }
}
```

---

## Summary

| Concern | Implementation |
|---------|---------------|
| Tenant identification | `HeaderResolver` — reads `X-Tenant-ID` header |
| Data isolation | `TenantAwareFilter` — SQL `WHERE tenant_id = '<slug>'` |
| Configuration | `driver: shared_db`, `resolvers: ['header']` |
| Missing header | `TenantMissingException` → custom exception listener |
| Testing | `WebTestCase` with `HTTP_X_TENANT_ID` server var |

## See Also

- [Shared-DB Driver](../shared-db.md) — full driver documentation
- [Strict Mode](../strict-mode.md) — handling unauthenticated requests
- [Resolvers](../resolvers.md) — available tenant resolvers
- [Examples: SaaS Subdomain](saas-subdomain.md) — database-per-tenant tutorial
