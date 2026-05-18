<?php

// Fixture: last array entry has no trailing comma.
// Exercises the WR-02 edge case: buildMutatedSource() must insert the comma
// inline after the last entry, not on a bare new line.

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true]
];
