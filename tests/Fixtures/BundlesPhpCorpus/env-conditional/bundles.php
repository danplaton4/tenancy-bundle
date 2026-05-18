<?php

// Fixture: project with a top-level if-block after the bundles array — exercises
// the "more than one top-level statement" refusal branch of the detector.

$bundles = [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
];

if (isset($_SERVER['APP_ENV']) && 'dev' === $_SERVER['APP_ENV']) {
    $bundles[Symfony\Bundle\DebugBundle\DebugBundle::class] = ['dev' => true];
}

return $bundles;
