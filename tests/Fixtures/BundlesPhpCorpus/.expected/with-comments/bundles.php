<?php

// Fixture provenance: adapted from sulu/sulu@3.0 config/bundles.php
// Source: https://github.com/sulu/sulu/blob/3.0/config/bundles.php
// Blob sha: 02754748653f54e0b0b2c9411736d5ebf8747fe2
// Bundle list truncated to match skeleton/ fixture; leading docblock retained verbatim.

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Symfony\Bundle\MonologBundle\MonologBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Tenancy\Bundle\TenancyBundle::class => ['all' => true],
];

