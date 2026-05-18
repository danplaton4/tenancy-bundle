<?php

// Fixture: intentionally malformed bundles.php — the array is never closed.
// Parser::parse() returns null on unrecoverable parse errors; the detector
// MUST treat this branch as "non-standard, refuse to mutate".

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    // intentionally unclosed array — no `];` follows