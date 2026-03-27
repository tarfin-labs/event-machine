<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

/**
 * Result of selectTransitions() for parallel state event routing.
 *
 * Distinguishes three cases when branches is empty:
 * - "no handler exists" (both flags false)
 * - "handler exists but ValidationGuardBehavior failed" (hadValidationGuardFailure=true)
 * - "handler exists but regular guard/calculator failed" (hadRegularGuardFailure=true)
 */
final readonly class TransitionSelectionResult
{
    public function __construct(
        /** @var TransitionBranch[] */
        public array $branches,
        public bool $hadValidationGuardFailure,
        public bool $hadRegularGuardFailure = false,
    ) {}
}
