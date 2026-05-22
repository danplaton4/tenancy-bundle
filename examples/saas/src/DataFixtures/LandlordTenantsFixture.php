<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Landlord\DemoTenant;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

final class LandlordTenantsFixture extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $tenants = [
            [
                'slug' => 'acme',
                'name' => 'Acme Corporation',
                'brandColor' => '#f97316',
                'mailerDsn' => 'smtp://mailpit:1025',
                'mailerFrom' => 'noreply@acme.example',
                'mailerReplyTo' => 'support@acme.example',
                'connectionConfig' => ['dbname' => 'tenant_acme'],
            ],
            [
                'slug' => 'globex',
                'name' => 'Globex Industries',
                'brandColor' => '#2563eb',
                'mailerDsn' => 'smtp://mailpit:1025',
                'mailerFrom' => 'noreply@globex.example',
                'mailerReplyTo' => 'support@globex.example',
                'connectionConfig' => ['dbname' => 'tenant_globex'],
            ],
            [
                'slug' => 'initech',
                'name' => 'Initech LLC',
                'brandColor' => '#16a34a',
                'mailerDsn' => 'smtp://mailpit:1025',
                'mailerFrom' => 'noreply@initech.example',
                'mailerReplyTo' => 'support@initech.example',
                'connectionConfig' => ['dbname' => 'tenant_initech'],
            ],
        ];

        foreach ($tenants as $data) {
            $tenant = new DemoTenant($data['slug'], $data['name']);
            $tenant->setIsActive(true);
            $tenant->setBrandColor($data['brandColor']);
            $tenant->setMailerDsn($data['mailerDsn']);
            $tenant->setMailerFrom($data['mailerFrom']);
            $tenant->setMailerReplyTo($data['mailerReplyTo']);
            $tenant->setConnectionConfig($data['connectionConfig']);

            $manager->persist($tenant);
        }

        $manager->flush();
    }
}
