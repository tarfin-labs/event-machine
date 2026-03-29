<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use LogicException;

/**
 * Thrown when machine configuration has structural errors.
 *
 * Covers state definitions, transitions, delegation, job actors,
 * forward endpoints, and all other config validated by StateConfigValidator.
 */
class InvalidStateConfigException extends LogicException
{
    // ── Root config ────────────────────────────────────────────────────

    public static function invalidRootKeys(array $keys, array $allowed): self
    {
        return new self(
            message: 'Invalid root level configuration keys: '.implode(', ', $keys).
                '. Allowed keys are: '.implode(', ', $allowed)
        );
    }

    public static function statesMustBeArray(): self
    {
        return new self(message: 'States configuration must be an array.');
    }

    // ── Listen config ──────────────────────────────────────────────────

    public static function invalidListenConfig(): self
    {
        return new self(message: "The 'listen' configuration must be an array.");
    }

    public static function invalidListenKeys(array $keys, array $allowed): self
    {
        return new self(
            message: "Invalid 'listen' keys: ".implode(', ', $keys).
                '. Allowed keys are: '.implode(', ', $allowed)
        );
    }

    // ── State keys & types ─────────────────────────────────────────────

    public static function alwaysMustBeUnderOn(string $path): self
    {
        return new self(
            message: "State '{$path}' has transitions defined directly. ".
                "All transitions including '@always' must be defined under the 'on' key."
        );
    }

    public static function invalidStateKeys(string $path, array $keys, array $allowed): self
    {
        return new self(
            message: "State '{$path}' has invalid keys: ".implode(', ', $keys).
                '. Allowed keys are: '.implode(', ', $allowed)
        );
    }

    public static function invalidStateType(string $path, string $type, array $allowed): self
    {
        return new self(
            message: "State '{$path}' has invalid type: {$type}. ".
                'Allowed types are: '.implode(', ', $allowed)
        );
    }

    public static function invalidStatesConfig(string $path): self
    {
        return new self(
            message: "State '{$path}' has invalid states configuration. States must be an array."
        );
    }

    // ── Final state constraints ────────────────────────────────────────

    public static function finalStateCannotHaveTransitions(string $path): self
    {
        return new self(
            message: "Final state '{$path}' cannot have transitions"
        );
    }

    public static function finalStateCannotHaveChildStates(string $path): self
    {
        return new self(
            message: "Final state '{$path}' cannot have child states"
        );
    }

    // ── Parallel state constraints ─────────────────────────────────────

    public static function parallelStateMustHaveRegions(string $path): self
    {
        return new self(
            message: "Parallel state '{$path}' must have at least one region defined in 'states'."
        );
    }

    // ── Entry/exit actions ─────────────────────────────────────────────

    public static function invalidStateActions(string $path): self
    {
        return new self(
            message: "State '{$path}' has invalid entry/exit actions configuration. ".
                'Actions must be an array or string.'
        );
    }

    // ── @done / @fail / @done.{state} ──────────────────────────────────

    public static function emptyDoneDotSuffix(string $path): self
    {
        return new self(
            message: "State '{$path}' has invalid key '@done.' — final state name after the dot cannot be empty."
        );
    }

    public static function doneDotRequiresMachine(string $path): self
    {
        return new self(
            message: "State '{$path}' has @done.{state} keys but no 'machine' delegation. "
                .'Per-final-state routing is only valid on states that delegate to a child machine.'
        );
    }

    public static function invalidDoneFailConfig(string $path, string $key): self
    {
        return new self(
            message: "State '{$path}' has invalid '{$key}' configuration. Must be a string or array."
        );
    }

    // ── Transitions ────────────────────────────────────────────────────

    public static function invalidTransitionsConfig(string $path): self
    {
        return new self(
            message: "State '{$path}' has invalid 'on' definition. 'on' must be an array of transitions."
        );
    }

    public static function invalidTransitionFormat(string $path, string $event): self
    {
        return new self(
            message: "State '{$path}' has invalid transition for event '{$event}'. ".
                'Transition must be a string (target state) or an array (transition config).'
        );
    }

    public static function invalidTransitionKeys(string $path, string $event, array $keys, array $allowed): self
    {
        return new self(
            message: "State '{$path}' has invalid keys in transition config for event '{$event}': ".
                implode(', ', $keys).
                '. Allowed keys are: '.implode(', ', $allowed)
        );
    }

    public static function invalidBehaviorConfig(string $path, string $event, string $behavior): self
    {
        return new self(
            message: "State '{$path}' has invalid {$behavior} configuration for event '{$event}'. ".
                ucfirst($behavior).' must be an array or string.'
        );
    }

    // ── Guarded transitions ────────────────────────────────────────────

    public static function emptyGuardedTransitions(string $path, string $event): self
    {
        return new self(
            message: "State '{$path}' has empty conditions array for event '{$event}'. ".
                'Guarded transitions must have at least one condition.'
        );
    }

    public static function invalidGuardedCondition(string $path, string $event): self
    {
        return new self(
            message: "State '{$path}' has invalid condition in transition for event '{$event}'. ".
                'Each condition must be an array with target/guards/actions.'
        );
    }

    public static function defaultGuardMustBeLast(string $path, string $event): self
    {
        return new self(
            message: "State '{$path}' has invalid conditions order for event '{$event}'. ".
                'Default condition (no guards) must be the last condition.'
        );
    }

    // ── Machine delegation ─────────────────────────────────────────────

    public static function invalidMachineValue(string $path): self
    {
        return new self(
            message: "State '{$path}' has invalid 'machine' value. Must be a string (machine class FQCN)."
        );
    }

    public static function machineAndParallelConflict(string $path): self
    {
        return new self(
            message: "State '{$path}' cannot have both 'machine' and type 'parallel'. Machine delegation is only for atomic states."
        );
    }

    public static function forwardRequiresQueue(string $path): self
    {
        return new self(
            message: "State '{$path}' has 'forward' without 'queue'. Event forwarding is only valid in async mode."
        );
    }

    public static function invalidForwardKeys(string $path, string $entry, array $unknownKeys, array $allowed): self
    {
        return new self(
            message: "State '{$path}' forward entry '{$entry}' has unknown keys: ".implode(', ', $unknownKeys)
                .'. Allowed: '.implode(', ', $allowed)
        );
    }

    public static function forwardUriMustStartWithSlash(string $path, string $entry): self
    {
        return new self(
            message: "State '{$path}' forward entry '{$entry}' uri must start with '/'."
        );
    }

    public static function fireAndForgetCannotHaveFail(string $path): self
    {
        return new self(
            message: "State '{$path}' has '@fail' without '@done'. "
                .'Fire-and-forget machine delegation does not support failure callbacks. '
                ."Add '@done' for managed delegation, or remove '@fail'."
        );
    }

    public static function fireAndForgetCannotHaveTimeout(string $path): self
    {
        return new self(
            message: "State '{$path}' has '@timeout' without '@done'. "
                .'Fire-and-forget machine delegation does not support timeout callbacks. '
                ."Add '@done' for managed delegation, or remove '@timeout'."
        );
    }

    public static function fireAndForgetCannotHaveOutput(string $path): self
    {
        return new self(
            message: "State '{$path}' has 'output' without '@done'. "
                .'Fire-and-forget machine delegation does not produce output for the parent.'
        );
    }

    public static function fireAndForgetCannotHaveForward(string $path): self
    {
        return new self(
            message: "State '{$path}' has 'forward' without '@done'. "
                .'Fire-and-forget machine delegation does not support event forwarding.'
        );
    }

    public static function targetRequiresQueue(string $path): self
    {
        return new self(
            message: "State '{$path}' has 'machine' with 'target' but no 'queue'. "
                .'Fire-and-forget with immediate transition requires async execution.'
        );
    }

    public static function targetAndDoneConflict(string $path): self
    {
        return new self(
            message: "State '{$path}' cannot have both '@done' and 'target' with 'machine'. "
                ."Use '@done' for managed delegation or 'target' for fire-and-forget."
        );
    }

    public static function uncoveredChildFinalStates(string $path, string $machineClass, array $states): self
    {
        return new self(
            message: "State '{$path}' has @done.{state} routing but child machine "
                ."'{$machineClass}' has uncovered final states: "
                .implode(', ', $states)
                .". Add specific '@done.{state}' keys or a catch-all '@done' to handle all outcomes."
        );
    }

    // ── Cross-region transitions ───────────────────────────────────────

    public static function crossRegionTransition(
        string $parallelPath,
        string $regionName,
        string $sourceName,
        string $target,
        string $siblingRegionName,
    ): self {
        return new self(
            message: "Cross-region transition not allowed: state \"{$parallelPath}.{$regionName}.{$sourceName}\" "
                ."in region \"{$regionName}\" cannot target state \"{$target}\" in sibling region \"{$siblingRegionName}\". "
                .'Use events (raise/sendTo) to coordinate between regions.'
        );
    }

    // ── Job actor config ───────────────────────────────────────────────

    public static function invalidJobValue(string $path): self
    {
        return new self(
            message: "State '{$path}' has invalid 'job' value. Must be a string (job class FQCN)."
        );
    }

    public static function jobAndMachineConflict(string $path): self
    {
        return new self(
            message: "State '{$path}' cannot have both 'job' and 'machine'. Use one or the other."
        );
    }

    public static function jobAndParallelConflict(string $path): self
    {
        return new self(
            message: "State '{$path}' cannot have both 'job' and type 'parallel'."
        );
    }

    public static function forwardWithJobNotAllowed(string $path): self
    {
        return new self(
            message: "State '{$path}' has 'forward' with 'job'. Event forwarding is only valid for machine delegation."
        );
    }

    public static function jobRequiresDoneOrTarget(string $path): self
    {
        return new self(
            message: "State '{$path}' has 'job' without '@done' or 'target'. Either define '@done' (managed) or 'target' (fire-and-forget)."
        );
    }

    public static function jobDoneAndTargetConflict(string $path): self
    {
        return new self(
            message: "State '{$path}' cannot have both '@done' and 'target'. Use '@done' for managed jobs or 'target' for fire-and-forget."
        );
    }

    // ── Normalization ──────────────────────────────────────────────────

    public static function valueMustBeStringArrayOrNull(): self
    {
        return new self(message: 'Value must be string, array or null');
    }
}
