<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Exceptions\InvalidStateConfigException;
use Tarfinlabs\EventMachine\Exceptions\InvalidListenerDefinitionException;
use Tarfinlabs\EventMachine\Exceptions\InvalidParallelStateDefinitionException;

class StateConfigValidator
{
    /** Allowed keys at different levels of the machine configuration */
    private const ALLOWED_ROOT_KEYS = [
        'id', 'version', 'initial', 'status_events', 'context', 'states', 'on', 'type',
        'meta', 'entry', 'exit', 'description', 'scenarios_enabled',
        'should_persist', 'delimiter', 'listen',
    ];

    private const ALLOWED_LISTEN_KEYS = ['entry', 'exit', 'transition'];

    private const ALLOWED_STATE_KEYS = [
        'id', 'on', 'states', 'initial', 'type', 'meta', 'entry', 'exit', 'description', 'output', '@done', '@fail',
        'machine', 'with', 'forward', 'queue', 'connection', '@timeout', 'retry', 'output',
        'job', 'target',
    ];

    private const ALLOWED_TRANSITION_KEYS = [
        'target', 'guards', 'actions', 'description', 'calculators',
        'after', 'every', 'max', 'then',
    ];

    /** Valid state types matching StateDefinitionType enum */
    private const VALID_STATE_TYPES = [
        'atomic', 'compound', 'parallel', 'final',
    ];

    /**
     * Validates the machine configuration structure.
     *
     * This method serves as the entry point for configuration validation.
     * It validates the root level configuration and recursively validates
     * all nested states and their transitions.
     *
     * @throws InvalidStateConfigException When configuration validation fails
     */
    public static function validate(?array $config): void
    {
        if ($config === null) {
            return;
        }

        // Validate root level configuration
        self::validateRootConfig($config);

        // Validate states if they exist
        if (isset($config['states'])) {
            if (!is_array($config['states'])) {
                throw InvalidStateConfigException::statesMustBeArray();
            }

            foreach ($config['states'] as $stateName => $stateConfig) {
                self::validateStateConfig($stateConfig, $stateName);
            }
        }

        // Validate root level transitions if they exist
        if (isset($config['on'])) {
            self::validateTransitionsConfig(
                transitionsConfig: $config['on'],
                path: 'root',
                parentState: $config
            );
        }
    }

    /**
     * Validates root level configuration.
     *
     * @throws InvalidStateConfigException
     */
    private static function validateRootConfig(array $config): void
    {
        $invalidRootKeys = array_diff(
            array_keys($config),
            self::ALLOWED_ROOT_KEYS
        );

        if ($invalidRootKeys !== []) {
            throw InvalidStateConfigException::invalidRootKeys($invalidRootKeys, self::ALLOWED_ROOT_KEYS);
        }

        if (isset($config['listen'])) {
            self::validateListenConfig($config['listen']);
        }
    }

    private static function validateListenConfig(mixed $listen): void
    {
        if (!is_array($listen)) {
            throw InvalidStateConfigException::invalidListenConfig();
        }

        $invalidKeys = array_diff(array_keys($listen), self::ALLOWED_LISTEN_KEYS);

        if ($invalidKeys !== []) {
            throw InvalidStateConfigException::invalidListenKeys($invalidKeys, self::ALLOWED_LISTEN_KEYS);
        }

        // Validate individual listener elements — reject old class-as-key format
        foreach (self::ALLOWED_LISTEN_KEYS as $hookKey) {
            if (!isset($listen[$hookKey])) {
                continue;
            }

            $raw = is_array($listen[$hookKey]) ? $listen[$hookKey] : [$listen[$hookKey]];

            foreach ($raw as $k => $v) {
                // Old format: ClassName::class => ['queue' => true] — string key with array value
                if (is_string($k) && is_array($v)) {
                    throw InvalidListenerDefinitionException::classAsKey($k);
                }
            }
        }
    }

    /**
     * Validates a single state's configuration.
     *
     * This method performs comprehensive validation of a state's configuration including:
     * - Checking for directly defined transitions
     * - Validating state keys
     * - Validating state type
     * - Validating entry/exit actions
     * - Processing nested states
     * - Validating transitions
     *
     * The validation order is important: we validate nested states first,
     * then process the transitions to ensure proper context.
     *
     * @throws InvalidStateConfigException When state configuration is invalid
     */
    private static function validateStateConfig(?array $stateConfig, string $path): void
    {
        if ($stateConfig === null) {
            return;
        }

        // Check for transitions defined outside 'on'
        if (isset($stateConfig['@always']) || array_key_exists(key: '@always', array: $stateConfig)) {
            throw InvalidStateConfigException::alwaysMustBeUnderOn($path);
        }

        // Validate state keys (filter out dynamic @done.{state} keys before checking)
        $configKeysToValidate = array_filter(
            array_keys($stateConfig),
            fn (string $k): bool => !str_starts_with($k, '@done.'),
        );
        $invalidKeys = array_diff($configKeysToValidate, self::ALLOWED_STATE_KEYS);
        if ($invalidKeys !== []) {
            throw InvalidStateConfigException::invalidStateKeys($path, $invalidKeys, self::ALLOWED_STATE_KEYS);
        }

        // Validate state type if specified
        if (isset($stateConfig['type'])) {
            self::validateStateType(stateConfig: $stateConfig, path: $path);
        }

        // Validate entry/exit actions
        self::validateStateActions(stateConfig: $stateConfig, path: $path);

        // Final state validations
        if (isset($stateConfig['type']) && $stateConfig['type'] === 'final') {
            self::validateFinalState(stateConfig: $stateConfig, path: $path);
        }

        // Parallel state validations
        if (isset($stateConfig['type']) && $stateConfig['type'] === 'parallel') {
            self::validateParallelState(stateConfig: $stateConfig, path: $path);
        }

        // Validate @done/@fail configurations
        if (isset($stateConfig['@done'])) {
            self::validateDoneFailConfig($stateConfig['@done'], '@done', $path);
        }
        if (isset($stateConfig['@fail'])) {
            self::validateDoneFailConfig($stateConfig['@fail'], '@fail', $path);
        }

        // Validate @done.{state} configurations (before machine config, so coverage check sees valid keys)
        foreach ($stateConfig as $key => $value) {
            if (str_starts_with((string) $key, '@done.')) {
                $suffix = substr((string) $key, 6);

                if ($suffix === '') {
                    throw InvalidStateConfigException::emptyDoneDotSuffix($path);
                }

                self::validateDoneFailConfig($value, $key, $path);
            }
        }

        // @done.{state} requires machine delegation
        if (self::hasDoneDotKeys($stateConfig) && !isset($stateConfig['machine'])) {
            throw InvalidStateConfigException::doneDotRequiresMachine($path);
        }

        // Validate machine delegation configuration (after @done.{state} validation)
        if (isset($stateConfig['machine'])) {
            self::validateMachineConfig($stateConfig, $path);
        }

        // Validate job actor configuration
        if (isset($stateConfig['job'])) {
            self::validateJobConfig($stateConfig, $path);
        }

        // Process nested states first to ensure proper context
        if (isset($stateConfig['states'])) {
            if (!is_array($stateConfig['states'])) {
                throw InvalidStateConfigException::invalidStatesConfig($path);
            }

            foreach ($stateConfig['states'] as $childKey => $childState) {
                self::validateStateConfig(
                    stateConfig: $childState,
                    path: "{$path}.{$childKey}"
                );
            }
        }

        // Validate transitions after processing nested states
        if (isset($stateConfig['on'])) {
            self::validateTransitionsConfig(
                transitionsConfig: $stateConfig['on'],
                path: $path,
                parentState: $stateConfig
            );
        }
    }

    /**
     * Validates state type configuration.
     *
     * @throws InvalidStateConfigException
     */
    private static function validateStateType(array $stateConfig, string $path): void
    {
        if (!in_array($stateConfig['type'], haystack: self::VALID_STATE_TYPES, strict: true)) {
            throw InvalidStateConfigException::invalidStateType($path, $stateConfig['type'], self::VALID_STATE_TYPES);
        }
    }

    /**
     * Validates final state constraints.
     *
     * @throws InvalidStateConfigException
     */
    private static function validateFinalState(array $stateConfig, string $path): void
    {
        if (isset($stateConfig['on'])) {
            throw InvalidStateConfigException::finalStateCannotHaveTransitions($path);
        }

        if (isset($stateConfig['states'])) {
            throw InvalidStateConfigException::finalStateCannotHaveChildStates($path);
        }
    }

    /**
     * Validates parallel state constraints.
     *
     * @throws InvalidStateConfigException|InvalidParallelStateDefinitionException
     */
    private static function validateParallelState(array $stateConfig, string $path): void
    {
        if (isset($stateConfig['initial'])) {
            throw InvalidParallelStateDefinitionException::cannotHaveInitial($path);
        }

        if (!isset($stateConfig['states']) || !is_array($stateConfig['states']) || $stateConfig['states'] === []) {
            throw InvalidStateConfigException::parallelStateMustHaveRegions($path);
        }

        // Validate no cross-region transitions exist
        self::validateNoCrossRegionTransitions($stateConfig['states'], $path);
    }

    /**
     * Validates state entry and exit actions.
     *
     * @throws InvalidStateConfigException
     */
    private static function validateStateActions(array $stateConfig, string $path): void
    {
        foreach (['entry', 'exit'] as $actionType) {
            if (isset($stateConfig[$actionType])) {
                $actions = $stateConfig[$actionType];
                if (!is_string($actions) && !is_array($actions)) {
                    throw InvalidStateConfigException::invalidStateActions($path);
                }
            }
        }
    }

    /**
     * Validates the transitions configuration for a state.
     *
     * This method processes both standard event names and Event class names as transition triggers.
     * It ensures that all transitions are properly formatted and contain valid configuration.
     *
     * @throws InvalidStateConfigException When transitions configuration is invalid
     */
    private static function validateTransitionsConfig(
        mixed $transitionsConfig,
        string $path,
        ?array $parentState = null
    ): void {
        if (!is_array($transitionsConfig)) {
            throw InvalidStateConfigException::invalidTransitionsConfig($path);
        }

        foreach ($transitionsConfig as $eventName => $transition) {
            // Handle both Event classes and string event names
            if (is_string($eventName) && class_exists($eventName) && is_subclass_of($eventName, EventBehavior::class)) {
                self::validateTransition(
                    transition: $transition,
                    path: $path,
                    eventName: $eventName::getType()
                );

                continue;
            }

            self::validateTransition(
                transition: $transition,
                path: $path,
                eventName: $eventName
            );
        }
    }

    /**
     * Validates a single transition configuration.
     */
    private static function validateTransition(
        mixed $transition,
        string $path,
        string $eventName
    ): void {
        // Normalize empty values to null (targetless transition)
        if (in_array($transition, [null, '', []], true)) {
            return;
        }

        if (is_string($transition)) {
            return;
        }

        if (!is_array($transition)) {
            throw InvalidStateConfigException::invalidTransitionFormat($path, $eventName);
        }

        // Strip timer keys (after/every/max/then) before validating transition structure
        $timerKeys               = ['after', 'every', 'max', 'then'];
        $transitionWithoutTimers = array_diff_key($transition, array_flip($timerKeys));

        // If it's a list (guarded transitions) — includes empty lists
        if (array_is_list($transitionWithoutTimers)) {
            self::validateGuardedTransitions($transitionWithoutTimers, $path, $eventName);
            foreach ($transitionWithoutTimers as &$condition) {
                self::validateTransitionConfig(transitionConfig: $condition, path: $path, eventName: $eventName);
            }

            return;
        }

        // Standard single-branch config
        self::validateTransitionConfig(transitionConfig: $transitionWithoutTimers, path: $path, eventName: $eventName);
    }

    /**
     * Validates the configuration of a single transition.
     */
    private static function validateTransitionConfig(
        array &$transitionConfig,
        string $path,
        string $eventName
    ): void {
        // Validate allowed keys
        $invalidKeys = array_diff(array_keys($transitionConfig), self::ALLOWED_TRANSITION_KEYS);
        if ($invalidKeys !== []) {
            throw InvalidStateConfigException::invalidTransitionKeys($path, $eventName, $invalidKeys, self::ALLOWED_TRANSITION_KEYS);
        }

        // Normalize and validate behaviors
        self::validateTransitionBehaviors(transitionConfig: $transitionConfig, path: $path, eventName: $eventName);
    }

    /**
     * Validates and normalizes transition behaviors (guards, actions, calculators).
     */
    private static function validateTransitionBehaviors(
        array &$transitionConfig,
        string $path,
        string $eventName
    ): void {
        $behaviors = [
            'guards'      => 'Guards',
            'actions'     => 'Actions',
            'calculators' => 'Calculators',
        ];

        foreach (array_keys($behaviors) as $behavior) {
            if (isset($transitionConfig[$behavior])) {
                try {
                    $transitionConfig[$behavior] = self::normalizeArrayOrString(value: $transitionConfig[$behavior]);
                } catch (InvalidStateConfigException) {
                    throw InvalidStateConfigException::invalidBehaviorConfig($path, $eventName, $behavior);
                }
            }
        }
    }

    /**
     * Validates guarded transitions with multiple conditions.
     */
    private static function validateGuardedTransitions(array $conditions, string $path, string $eventName): void
    {
        if ($conditions === []) {
            throw InvalidStateConfigException::emptyGuardedTransitions($path, $eventName);
        }

        foreach ($conditions as $index => $condition) {
            if (!is_array($condition)) {
                throw InvalidStateConfigException::invalidGuardedCondition($path, $eventName);
            }

            // If this is not the last condition and it has no guards
            if (!isset($condition['guards']) && $index !== count($conditions) - 1) {
                throw InvalidStateConfigException::defaultGuardMustBeLast($path, $eventName);
            }
        }
    }

    /**
     * Validates @done/@fail configuration format.
     *
     * Accepts the same formats as regular transitions: string target,
     * single config object, or conditional array of objects with guards.
     *
     * @throws InvalidStateConfigException
     */
    private static function validateDoneFailConfig(mixed $config, string $key, string $path): void
    {
        if (is_string($config)) {
            return;
        }

        if (!is_array($config)) {
            throw InvalidStateConfigException::invalidDoneFailConfig($path, $key);
        }

        if (array_is_list($config)) {
            self::validateGuardedTransitions($config, $path, $key);
            foreach ($config as &$condition) {
                self::validateTransitionConfig(transitionConfig: $condition, path: $path, eventName: $key);
            }
        } else {
            self::validateTransitionConfig(transitionConfig: $config, path: $path, eventName: $key);
        }
    }

    /**
     * Validates machine delegation configuration.
     *
     * @throws InvalidStateConfigException
     */
    private static function validateMachineConfig(array $stateConfig, string $path): void
    {
        $machineClass = $stateConfig['machine'];

        // machine value must be a string (FQCN)
        if (!is_string($machineClass)) {
            throw InvalidStateConfigException::invalidMachineValue($path);
        }

        // machine + type:parallel are mutually exclusive
        if (isset($stateConfig['type']) && $stateConfig['type'] === 'parallel') {
            throw InvalidStateConfigException::machineAndParallelConflict($path);
        }

        // forward requires queue (only valid in async mode)
        if (!empty($stateConfig['forward']) && !isset($stateConfig['queue'])) {
            throw InvalidStateConfigException::forwardRequiresQueue($path);
        }

        // Validate Format 3 forward array entries
        if (!empty($stateConfig['forward'])) {
            $allowedForwardKeys = ['child_event', 'uri', 'method', 'middleware', 'action', 'output', 'status', 'available_events'];

            foreach ($stateConfig['forward'] as $key => $value) {
                if (is_string($key) && is_array($value)) {
                    $unknownKeys = array_diff(array_keys($value), $allowedForwardKeys);

                    if ($unknownKeys !== []) {
                        throw InvalidStateConfigException::invalidForwardKeys($path, $key, $unknownKeys, $allowedForwardKeys);
                    }

                    if (isset($value['uri']) && !str_starts_with((string) $value['uri'], '/')) {
                        throw InvalidStateConfigException::forwardUriMustStartWithSlash($path, $key);
                    }
                }
            }
        }

        // Fire-and-forget detection: async (queue) + no @done (including @done.{state})
        $hasDoneRouting  = isset($stateConfig['@done']) || self::hasDoneDotKeys($stateConfig);
        $isFireAndForget = isset($stateConfig['queue']) && !$hasDoneRouting;

        if ($isFireAndForget) {
            if (isset($stateConfig['@fail'])) {
                throw InvalidStateConfigException::fireAndForgetCannotHaveFail($path);
            }

            if (isset($stateConfig['@timeout'])) {
                throw InvalidStateConfigException::fireAndForgetCannotHaveTimeout($path);
            }

            if (isset($stateConfig['output'])) {
                throw InvalidStateConfigException::fireAndForgetCannotHaveOutput($path);
            }

            if (!empty($stateConfig['forward'])) {
                throw InvalidStateConfigException::fireAndForgetCannotHaveForward($path);
            }
        }

        // target validation (fire-and-forget with immediate transition)
        if (isset($stateConfig['target'])) {
            if (!isset($stateConfig['queue'])) {
                throw InvalidStateConfigException::targetRequiresQueue($path);
            }

            if (isset($stateConfig['@done'])) {
                throw InvalidStateConfigException::targetAndDoneConflict($path);
            }
        }

        // Validate @done.{state} coverage against child machine's final states
        self::validateChildFinalStateCoverage($stateConfig, $path);
    }

    /**
     * Validates job actor configuration.
     *
     * @throws InvalidStateConfigException
     */
    private static function validateJobConfig(array $stateConfig, string $path): void
    {
        $jobClass = $stateConfig['job'];

        if (!is_string($jobClass)) {
            throw InvalidStateConfigException::invalidJobValue($path);
        }

        if (isset($stateConfig['machine'])) {
            throw InvalidStateConfigException::jobAndMachineConflict($path);
        }

        if (isset($stateConfig['type']) && $stateConfig['type'] === 'parallel') {
            throw InvalidStateConfigException::jobAndParallelConflict($path);
        }

        if (!empty($stateConfig['forward'])) {
            throw InvalidStateConfigException::forwardWithJobNotAllowed($path);
        }

        if (!isset($stateConfig['@done']) && !isset($stateConfig['target'])) {
            throw InvalidStateConfigException::jobRequiresDoneOrTarget($path);
        }

        if (isset($stateConfig['@done']) && isset($stateConfig['target'])) {
            throw InvalidStateConfigException::jobDoneAndTargetConflict($path);
        }
    }

    /**
     * Normalizes the given value into an array or returns null.
     *
     * @throws InvalidStateConfigException If the value is neither string, array, nor null.
     */
    private static function normalizeArrayOrString(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return [$value];
        }

        if (is_array($value)) {
            return $value;
        }

        throw InvalidStateConfigException::valueMustBeStringArrayOrNull();
    }

    /**
     * Check if config contains any @done.{state} keys.
     */
    private static function hasDoneDotKeys(array $config): bool
    {
        foreach (array_keys($config) as $key) {
            if (str_starts_with((string) $key, '@done.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate that all child machine final states are covered by @done.{state} keys or a @done catch-all.
     *
     * Only runs when @done.{state} keys are present. Skips gracefully if child class is unresolvable.
     *
     * @throws InvalidStateConfigException When child final states are uncovered.
     */
    private static function validateChildFinalStateCoverage(array $stateConfig, string $path): void
    {
        $doneStateKeys = [];

        foreach (array_keys($stateConfig) as $key) {
            if (str_starts_with((string) $key, '@done.')) {
                $doneStateKeys[] = substr((string) $key, 6);
            }
        }

        // No dot notation used → skip (backward compatible)
        if ($doneStateKeys === []) {
            return;
        }

        // Has catch-all @done → all states covered
        if (isset($stateConfig['@done'])) {
            return;
        }

        // Resolve child machine's final states
        $machineClass = $stateConfig['machine'];

        if (!is_string($machineClass) || !class_exists($machineClass)) {
            return;
        }

        try {
            $childDefinition = $machineClass::definition();
        } catch (\Throwable) {
            return;
        }

        $childFinalStates = self::collectFinalStateKeys($childDefinition->config);

        $uncovered = array_diff($childFinalStates, $doneStateKeys);

        if ($uncovered !== []) {
            throw InvalidStateConfigException::uncoveredChildFinalStates($path, $machineClass, $uncovered);
        }
    }

    /**
     * Validates that no transition in a parallel region targets a state in a sibling region.
     *
     * @param  array<string, array|null>  $regions  The regions defined under a parallel state
     * @param  string  $parallelPath  The dot-path of the parallel state
     *
     * @throws InvalidStateConfigException When a cross-region transition is detected
     */
    private static function validateNoCrossRegionTransitions(array $regions, string $parallelPath): void
    {
        // Build a map: regionName => [stateNames in that region]
        $statesByRegion = [];

        foreach ($regions as $regionName => $regionConfig) {
            $statesByRegion[$regionName] = isset($regionConfig['states']) && is_array($regionConfig['states'])
                ? array_keys($regionConfig['states'])
                : [];
        }

        // For each region, collect transition targets and check against sibling regions
        foreach ($regions as $regionName => $regionConfig) {
            if (!isset($regionConfig['states'])) {
                continue;
            }
            if (!is_array($regionConfig['states'])) {
                continue;
            }

            $ownStates         = $statesByRegion[$regionName];
            $transitionTargets = self::collectTransitionTargets($regionConfig['states']);

            foreach ($transitionTargets as [$sourceName, $target]) {
                // If the target exists in this region, it's a valid intra-region transition
                if (in_array($target, $ownStates, true)) {
                    continue;
                }

                // Target not found in own region — check if it exists in a sibling region
                foreach ($statesByRegion as $siblingRegionName => $siblingStates) {
                    if ($siblingRegionName === $regionName) {
                        continue;
                    }

                    if (in_array($target, $siblingStates, true)) {
                        throw InvalidStateConfigException::crossRegionTransition($parallelPath, $regionName, $sourceName, $target, $siblingRegionName);
                    }
                }
            }
        }
    }

    /**
     * Collect all transition targets from a set of states.
     *
     * @param  array<string, array|null>  $states
     *
     * @return array<array{0: string, 1: string}> List of [sourceStateName, targetStateName] pairs
     */
    private static function collectTransitionTargets(array $states): array
    {
        $targets = [];

        foreach ($states as $stateName => $stateConfig) {
            if (!is_array($stateConfig)) {
                continue;
            }
            if (!isset($stateConfig['on'])) {
                continue;
            }

            foreach ($stateConfig['on'] as $transitionConfig) {
                foreach (self::extractTargetsFromTransition($transitionConfig) as $target) {
                    $targets[] = [$stateName, $target];
                }
            }
        }

        return $targets;
    }

    /**
     * Extract target state names from a single transition config.
     *
     * @return array<string>
     */
    private static function extractTargetsFromTransition(mixed $transition): array
    {
        // String shorthand: 'SOME_EVENT' => 'target_state'
        if (is_string($transition) && $transition !== '') {
            return [$transition];
        }

        if (!is_array($transition)) {
            return [];
        }

        // Guarded transitions (list of condition objects)
        if (array_is_list($transition)) {
            $targets = [];

            foreach ($transition as $condition) {
                if (!is_array($condition)) {
                    continue;
                }
                if (!isset($condition['target'])) {
                    continue;
                }
                if (!is_string($condition['target'])) {
                    continue;
                }

                $targets[] = $condition['target'];
            }

            return $targets;
        }

        // Single transition config with 'target' key
        if (isset($transition['target']) && is_string($transition['target'])) {
            return [$transition['target']];
        }

        return [];
    }

    /**
     * Collect root-level final state key names from a machine config.
     *
     * @return array<string>
     */
    private static function collectFinalStateKeys(array $config): array
    {
        $finals = [];

        foreach ($config['states'] ?? [] as $name => $stateConfig) {
            if (isset($stateConfig['type']) && $stateConfig['type'] === 'final') {
                $finals[] = $name;
            }
        }

        return $finals;
    }
}
