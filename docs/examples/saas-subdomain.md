# SaaS Subdomain Example

This end-to-end tutorial shows how to build a subdomain-based multi-tenant SaaS application
using the database-per-tenant driver. Each organization gets its own subdomain (`acme.yourapp.com`)
and its own isolated database.

**Scenario**: A project management SaaS where each organization is a tenant with:

- Subdomain routing: `acme.yourapp.com`, `demo.yourapp.com`
- Isolated database per tenant (maximum data isolation)
- Shared landlord database for the tenant registry

---

## Step 1: Bundle Configuration

```yaml
# config/packages/tenancy.yaml
tenancy:
    driver: database_per_tenant
    database:
        enabled: true
    host:
        app_domain: yourapp.com  # subdomain resolver strips this suffix
```

The `host.app_domain` config tells the `HostResolver` to extract the slug from
`{slug}.yourapp.com`. For `acme.yourapp.com`, the resolved slug is `acme`.

---

## Step 2: Doctrine Configuration

Configure two connections and two entity managers — one for the landlord (tenant registry)
and one for the tenant (switched at runtime by `TenantDriverMiddleware`):

```yaml
# config/packages/doctrine.yaml (example for MySQL tenants)
doctrine:
    dbal:
        default_connection: landlord
        connections:
            landlord:
                url: '%env(DATABASE_URL)%'
            tenant:
                # Driver family MUST match your tenant databases.
                # Params below are merged with the active tenant's getConnectionConfig()
                # at connect() time by TenantDriverMiddleware; dbname is a placeholder.
                driver: pdo_mysql
                host: '%env(TENANT_DB_HOST)%'
                user: '%env(TENANT_DB_USER)%'
                password: '%env(TENANT_DB_PASSWORD)%'
                dbname: placeholder_tenant

    orm:
        default_entity_manager: landlord
        entity_managers:
            landlord:
                connection: landlord
                mappings:
                    AppLandlord:
                        type: attribute
                        dir: '%kernel.project_dir%/src/Entity/Landlord'
                        prefix: App\Entity\Landlord
            tenant:
                connection: tenant
                mappings:
                    AppTenant:
                        type: attribute
                        dir: '%kernel.project_dir%/src/Entity/Tenant'
                        prefix: App\Entity\Tenant
```

!!! warning "Driver family must match"
    The tenant connection's `driver` (e.g. `pdo_mysql`) must match the driver family of
    your actual tenant databases. The middleware merges tenant params at `connect()`
    time, but the driver itself is resolved from the placeholder at container boot.

---

## Step 3: Tenant Records

The built-in `Tenant` entity lives in the `landlord` database. Create tenants when onboarding
a new organization:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Tenancy\Bundle\Entity\Tenant;

final class TenantProvisioningService
{
    public function __construct(
        private readonly EntityManagerInterface $landlordEm,
    ) {}

    public function provision(string $slug, string $name, string $dbHost, string $dbName): Tenant
    {
        $tenant = new Tenant($slug, $name);
        $tenant->setDomain($slug.'.yourapp.com');
        $tenant->setConnectionConfig([
            'driver'   => 'pdo_mysql',
            'host'     => $dbHost,
            'port'     => 3306,
            'dbname'   => $dbName,
            'user'     => 'app_user',
            'password' => '%env(TENANT_DB_PASSWORD)%',
            'charset'  => 'utf8mb4',
        ]);

        $this->landlordEm->persist($tenant);
        $this->landlordEm->flush();

        return $tenant;
    }
}
```

---

## Step 4: Tenant-Scoped Entities

Map your application entities to the tenant entity manager:

```php
<?php

declare(strict_types=1);

namespace App\Entity\Tenant;

use App\Repository\ProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'projects')]
class Project
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'boolean')]
    private bool $isArchived = false;

    #[ORM\OneToMany(targetEntity: Task::class, mappedBy: 'project', cascade: ['persist', 'remove'])]
    private Collection $tasks;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->tasks = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getTasks(): Collection { return $this->tasks; }
}
```

There is no `tenant_id` column — isolation comes from the separate database, not a SQL filter.

---

## Step 5: Controller

The controller code is identical to non-tenanted code. Inject the **tenant** entity manager
explicitly (to avoid accidentally using the landlord EM):

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Tenant\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProjectController extends AbstractController
{
    public function __construct(
        // Inject the tenant EM by name
        #[\Symfony\Bridge\Doctrine\Attribute\MapEntity(entityManager: 'tenant')]
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/projects', methods: ['GET'])]
    public function list(): JsonResponse
    {
        // Queries the active tenant's database — no WHERE clause needed
        $projects = $this->em->getRepository(Project::class)->findAll();

        return $this->json(array_map(
            fn (Project $p) => ['id' => $p->getId(), 'name' => $p->getName()],
            $projects,
        ));
    }

    #[Route('/projects', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $project = new Project($data['name']);
        $this->em->persist($project);
        $this->em->flush();

        return $this->json(['id' => $project->getId()], Response::HTTP_CREATED);
    }
}
```

The controller has zero awareness of multi-tenancy — the bundle handles it completely.

---

## Step 6: Local Development

For local development, add subdomain entries to `/etc/hosts`:

```
127.0.0.1  acme.yourapp.local
127.0.0.1  demo.yourapp.local
```

And override `app_domain` in your dev config:

```yaml
# config/packages/dev/tenancy.yaml
tenancy:
    host:
        app_domain: yourapp.local
```

Then visit `http://acme.yourapp.local:8000` to test the `acme` tenant locally.

You can also use `tenancy:run` to execute commands for specific tenants during development:

```bash
# Clear cache for tenant 'acme'
bin/console tenancy:run acme "cache:clear"

# Create the schema for a new tenant database
bin/console tenancy:run acme "doctrine:schema:create --em=tenant"
```

---

## Step 7: Running Migrations

After provisioning a new tenant database, run migrations:

```bash
# Migrate all tenants
bin/console tenancy:migrate

# Migrate a single tenant
bin/console tenancy:migrate --tenant=acme
```

---

## Step 8: Testing Tenant Isolation

Use the `InteractsWithTenancy` trait to verify that tenant data is properly isolated:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Tenant\Project;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tenancy\Bundle\Testing\InteractsWithTenancy;

class ProjectIsolationTest extends KernelTestCase
{
    use InteractsWithTenancy;

    public function testAcmeCannotSeeDeprojects(): void
    {
        $doctrine = static::getContainer()->get('doctrine');

        // Set up 'acme' tenant and create a project
        $this->initializeTenant('acme');
        $acmeEm = $doctrine->resetManager('tenant');

        $acmeProject = new Project('Acme Roadmap');
        $acmeEm->persist($acmeProject);
        $acmeEm->flush();
        $this->clearTenant();

        // Set up 'demo' tenant — fresh :memory: database
        $this->initializeTenant('demo');
        $demoEm = $doctrine->resetManager('tenant');

        // 'demo' database is empty — cannot see 'acme' projects
        $demoProjects = $demoEm->getRepository(Project::class)->findAll();
        $this->assertCount(0, $demoProjects, 'demo tenant should not see acme projects');

        $this->assertTenantActive('demo');
    }

    public function testTenantCanManageOwnProjects(): void
    {
        $this->initializeTenant('acme');
        $em = static::getContainer()->get('doctrine')->resetManager('tenant');

        $project = new Project('Launch Campaign');
        $em->persist($project);
        $em->flush();

        $projects = $em->getRepository(Project::class)->findAll();
        $this->assertCount(1, $projects);
        $this->assertSame('Launch Campaign', $projects[0]->getName());
    }
}
```

---

## Summary

| Concern | Implementation |
|---------|---------------|
| Tenant identification | `HostResolver` — extracts slug from subdomain |
| Data isolation | `TenantDriverMiddleware` — per-tenant socket on the `tenant` DBAL connection |
| Entity manager | `tenant` EM — auto-switched on every request |
| Migrations | `tenancy:migrate` — per-tenant migration runner |
| Local dev | `/etc/hosts` entries + `tenancy.yaml` override |

## See Also

- [Database-per-Tenant Driver](../database-per-tenant.md) — full driver documentation
- [CLI Commands](../cli-commands.md) — `tenancy:migrate`, `tenancy:run`
- [Testing](../testing.md) — `InteractsWithTenancy` full reference
- [Examples: API Header](api-header.md) — shared-DB with REST API
