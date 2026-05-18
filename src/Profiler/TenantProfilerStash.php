<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Profiler;

/**
 * MINIMAL STUB FOR PARALLEL WORKTREE — Plan 19-02.
 *
 * Plan 19-01 (running in parallel) ships the full implementation of TenantProfilerStash
 * with event-listener attributes, ResetInterface, and capture semantics. Both planners
 * branched from the same base, so this worktree cannot see Plan 01's file.
 *
 * This stub exposes ONLY the public read API that TenantDataCollector (Plan 19-02)
 * depends on. At merge time, Plan 01's full implementation supersedes this file.
 *
 * @see Tenancy\Bundle\Profiler\TenantDataCollector
 */
class TenantProfilerStash
{
    public function getResolvedBy(): ?string
    {
        return null;
    }

    /**
     * @return string[]
     */
    public function getBootstrapperFqcns(): array
    {
        return [];
    }

    /**
     * @return array{class: string, message: string}|null
     */
    public function getCapturedException(): ?array
    {
        return null;
    }
}
