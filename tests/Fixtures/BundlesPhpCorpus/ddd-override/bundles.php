<?php

// Fixture: DDD project where config/bundles.php is a sentinel that throws — bundles
// are registered via Kernel::registerBundles() override instead. The AST detector
// must refuse to mutate this shape (top-level statement is `Throw_`, not `Return_`).

throw new \LogicException(
    'This project uses Kernel::registerBundles() — do not edit config/bundles.php.'
);
