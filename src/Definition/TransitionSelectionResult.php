<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

/**
 * Result of selectTransitions() for parallel state event routing.
 *
 * Distinguishes "no handler exists" (branches=[], flag=false)
 * from "handler exists but ValidationGuardBehavior failed" (branches=[], flag=true).
 */
final readonly class TransitionSelectionResult
{
    public function __construct(
        /** @var TransitionBranch[] */
        public array $branches,
        public bool $hadValidationGuardFailure,
    ) {}
}
