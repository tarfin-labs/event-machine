<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

use InvalidArgumentException;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Exceptions\InvalidParallelStateDefinitionException;

class StateConfigValidator
{
    /** Allowed keys at different levels of the machine configuration */
    private const ALLOWED_ROOT_KEYS = [
        'id', 'version', 'initial', 'status_events', 'context', 'states', 'on', 'type',
        'meta', 'entry', 'exit', 'description', 'scenarios_enabled',
        'should_persist', 'delimiter',
    ];

    private const ALLOWED_STATE_KEYS = [
        'id', 'on', 'states', 'initial', 'type', 'meta', 'entry', 'exit', 'description', 'result', '@done', '@fail',
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
     * @throws InvalidArgumentException When configuration validation fails
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
                throw new InvalidArgumentException(message: 'States configuration must be an array.');
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
     * @throws InvalidArgumentException
     */
    private static function validateRootConfig(array $config): void
    {
        $invalidRootKeys = array_diff(
            array_keys($config),
            self::ALLOWED_ROOT_KEYS
        );

        if ($invalidRootKeys !== []) {
            throw new InvalidArgumentException(
                message: 'Invalid root level configuration keys: '.implode(separator: ', ', array: $invalidRootKeys).
                '. Allowed keys are: '.implode(separator: ', ', array: self::ALLOWED_ROOT_KEYS)
            );
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
     * @throws InvalidArgumentException When state configuration is invalid
     */
    private static function validateStateConfig(?array $stateConfig, string $path): void
    {
        if ($stateConfig === null) {
            return;
        }

        // Check for transitions defined outside 'on'
        if (isset($stateConfig['@always']) || array_key_exists(key: '@always', array: $stateConfig)) {
            throw new InvalidArgumentException(
                message: "State '{$path}' has transitions defined directly. ".
                "All transitions including '@always' must be defined under the 'on' key."
            );
        }

        // Validate state keys (filter out dynamic @done.{state} keys before checking)
        $configKeysToValidate = array_filter(
            array_keys($stateConfig),
            fn (string $k): bool => !str_starts_with($k, '@done.'),
        );
        $invalidKeys = array_diff($configKeysToValidate, self::ALLOWED_STATE_KEYS);
        if ($invalidKeys !== []) {
            throw new InvalidArgumentException(
                message: "State '{$path}' has invalid keys: ".implode(separator: ', ', array: $invalidKeys).
                '. Allowed keys are: '.implode(separator: ', ', array: self::ALLOWED_STATE_KEYS)
            );
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
                    throw new InvalidArgumentException(
                        message: "State '{$path}' has invalid key '@done.' — final state name after the dot cannot be empty."
                    );
                }

                self::validateDoneFailConfig($value, $key, $path);
            }
        }

        // @done.{state} requires machine delegation
        if (self::hasDoneDotKeys($stateConfig) && !isset($stateConfig['machine'])) {
            throw new InvalidArgumentException(
                message: "State '{$path}' has @done.{state} keys but no 'machine' delegation. "
                    .'Per-final-state routing is only valid on states that delegate to a child machine.'
            );
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
                throw new InvalidArgumentException(
                    message: "State '{$path}' has invalid states configuration. States must be an array."
                );
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
     * @throws InvalidArgumentException
     */
    private static function validateStateType(array $stateConfig, string $path): void
    {
        if (!in_array($stateConfig['type'], haystack: self::VALID_STATE_TYPES, strict: true)) {
            throw new InvalidArgumentException(
                message: "State '{$path}' has invalid type: {$stateConfig['type']}. ".
                'Allowed types are: '.implode(separator: ', ', array: self::VALID_STATE_TYPES)
            );
        }
    }

    /**
     * Validates final state constraints.
     *
     * @throws InvalidArgumentException
     */
    private static function validateFinalState(array $stateConfig, string $path): void
    {
        if (isset($stateConfig['on'])) {
            throw new InvalidArgumentException(
                message: "Final state '{$path}' cannot have transitions"
            );
        }

        if (isset($stateConfig['states'])) {
            throw new InvalidArgumentException(
                message: "Final state '{$path}' cannot have child states"
            );
        }
    }

    /**
     * Validates parallel state constraints.
     *
     * @throws InvalidArgumentException
     */
    private static function validateParallelState(array $stateConfig, string $path): void
    {
        if (isset($stateConfig['initial'])) {
            throw InvalidParallelStateDefinitionException::cannotHaveInitial($path);
        }

        if (!isset($stateConfig['states']) || !is_array($stateConfig['states']) || $stateConfig['states'] === []) {
            throw new InvalidArgumentException(
                message: "Parallel state '{$path}' must have at least one region defined in 'states'."
            );
        }
    }

    /**
     * Validates state entry and exit actions.
     *
     * @throws InvalidArgumentException
     */
    private static function validateStateActions(array $stateConfig, string $path): void
    {
        foreach (['entry', 'exit'] as $actionType) {
            if (isset($stateConfig[$actionType])) {
                $actions = $stateConfig[$actionType];
                if (!is_string($actions) && !is_array($actions)) {
                    throw new InvalidArgumentException(
                        message: "State '{$path}' has invalid entry/exit actions configuration. ".
                        'Actions must be an array or string.'
                    );
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
     * @throws InvalidArgumentException When transitions configuration is invalid
     */
    private static function validateTransitionsConfig(
        mixed $transitionsConfig,
        string $path,
        ?array $parentState = null
    ): void {
        if (!is_array($transitionsConfig)) {
            throw new InvalidArgumentException(
                message: "State '{$path}' has invalid 'on' definition. 'on' must be an array of transitions."
            );
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
        if ($transition === null) {
            return;
        }

        if (is_string($transition)) {
            return;
        }

        if (!is_array($transition)) {
            throw new InvalidArgumentException(
                message: "State '{$path}' has invalid transition for event '{$eventName}'. ".
                'Transition must be a string (target state) or an array (transition config).'
            );
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
            throw new InvalidArgumentException(
                message: "State '{$path}' has invalid keys in transition config for event '{$eventName}': ".
                    implode(separator: ', ', array: $invalidKeys).
                    '. Allowed keys are: '.implode(separator: ', ', array: self::ALLOWED_TRANSITION_KEYS)
            );
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

        foreach ($behaviors as $behavior => $label) {
            if (isset($transitionConfig[$behavior])) {
                try {
                    $transitionConfig[$behavior] = self::normalizeArrayOrString(value: $transitionConfig[$behavior]);
                } catch (InvalidArgumentException) {
                    throw new InvalidArgumentException(
                        message: "State '{$path}' has invalid {$behavior} configuration for event '{$eventName}'. ".
                        "{$label} must be an array or string."
                    );
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
            throw new InvalidArgumentException(
                message: "State '{$path}' has empty conditions array for event '{$eventName}'. ".
                         'Guarded transitions must have at least one condition.'
            );
        }

        foreach ($conditions as $index => $condition) {
            if (!is_array($condition)) {
                throw new InvalidArgumentException(
                    message: "State '{$path}' has invalid condition in transition for event '{$eventName}'. ".
                             'Each condition must be an array with target/guards/actions.'
                );
            }

            // If this is not the last condition and it has no guards
            if (!isset($condition['guards']) && $index !== count($conditions) - 1) {
                throw new InvalidArgumentException(
                    message: "State '{$path}' has invalid conditions order for event '{$eventName}'. ".
                             'Default condition (no guards) must be the last condition.'
                );
            }
        }
    }

    /**
     * Validates @done/@fail configuration format.
     *
     * Accepts the same formats as regular transitions: string target,
     * single config object, or conditional array of objects with guards.
     *
     * @throws InvalidArgumentException
     */
    private static function validateDoneFailConfig(mixed $config, string $key, string $path): void
    {
        if (is_string($config)) {
            return;
        }

        if (!is_array($config)) {
            throw new InvalidArgumentException(
                message: "State '{$path}' has invalid '{$key}' configuration. Must be a string or array."
            );
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
     * @throws InvalidArgumentException
     */
    private static function validateMachineConfig(array $stateConfig, string $path): void
    {
        $machineClass = $stateConfig['machine'];

        // machine value must be a string (FQCN)
        if (!is_string($machineClass)) {
            throw new InvalidArgumentException(
                message: "State '{$path}' has invalid 'machine' value. Must be a string (machine class FQCN)."
            );
        }

        // machine + type:parallel are mutually exclusive
        if (isset($stateConfig['type']) && $stateConfig['type'] === 'parallel') {
            throw new InvalidArgumentException(
                message: "State '{$path}' cannot have both 'machine' and type 'parallel'. Machine delegation is only for atomic states."
            );
        }

        // forward requires queue (only valid in async mode)
        if (!empty($stateConfig['forward']) && !isset($stateConfig['queue'])) {
            throw new InvalidArgumentException(
                message: "State '{$path}' has 'forward' without 'queue'. Event forwarding is only valid in async mode."
            );
        }

        // Validate Format 3 forward array entries
        if (!empty($stateConfig['forward'])) {
            $allowedForwardKeys = ['child_event', 'uri', 'method', 'middleware', 'action', 'result', 'contextKeys', 'status', 'available_events'];

            foreach ($stateConfig['forward'] as $key => $value) {
                if (is_string($key) && is_array($value)) {
                    $unknownKeys = array_diff(array_keys($value), $allowedForwardKeys);

                    if ($unknownKeys !== []) {
                        throw new InvalidArgumentException(
                            message: "State '{$path}' forward entry '{$key}' has unknown keys: ".implode(', ', $unknownKeys)
                                .'. Allowed: '.implode(', ', $allowedForwardKeys)
                        );
                    }

                    if (isset($value['uri']) && !str_starts_with((string) $value['uri'], '/')) {
                        throw new InvalidArgumentException(
                            message: "State '{$path}' forward entry '{$key}' uri must start with '/'."
                        );
                    }
                }
            }
        }

        // Fire-and-forget detection: async (queue) + no @done (including @done.{state})
        $hasDoneRouting  = isset($stateConfig['@done']) || self::hasDoneDotKeys($stateConfig);
        $isFireAndForget = isset($stateConfig['queue']) && !$hasDoneRouting;

        if ($isFireAndForget) {
            // @fail is invalid — fire-and-forget doesn't track child failures
            if (isset($stateConfig['@fail'])) {
                throw new InvalidArgumentException(
                    message: "State '{$path}' has '@fail' without '@done'. "
                           .'Fire-and-forget machine delegation does not support failure callbacks. '
                           ."Add '@done' for managed delegation, or remove '@fail'."
                );
            }

            // @timeout is invalid — fire-and-forget doesn't track timing
            if (isset($stateConfig['@timeout'])) {
                throw new InvalidArgumentException(
                    message: "State '{$path}' has '@timeout' without '@done'. "
                           .'Fire-and-forget machine delegation does not support timeout callbacks. '
                           ."Add '@done' for managed delegation, or remove '@timeout'."
                );
            }

            // output is invalid — fire-and-forget doesn't receive results
            if (isset($stateConfig['output'])) {
                throw new InvalidArgumentException(
                    message: "State '{$path}' has 'output' without '@done'. "
                           .'Fire-and-forget machine delegation does not produce output for the parent.'
                );
            }

            // forward is invalid — no running child to forward to.
            // Note: the no-queue + forward case is already rejected above (line 483).
            // This branch only fires when queue IS present but @done is absent.
            if (!empty($stateConfig['forward'])) {
                throw new InvalidArgumentException(
                    message: "State '{$path}' has 'forward' without '@done'. "
                           .'Fire-and-forget machine delegation does not support event forwarding.'
                );
            }
        }

        // target validation (fire-and-forget with immediate transition)
        if (isset($stateConfig['target'])) {
            // target requires queue (sync fire-and-forget with transition is contradictory)
            if (!isset($stateConfig['queue'])) {
                throw new InvalidArgumentException(
                    message: "State '{$path}' has 'machine' with 'target' but no 'queue'. "
                           .'Fire-and-forget with immediate transition requires async execution.'
                );
            }

            // target + @done is ambiguous
            if (isset($stateConfig['@done'])) {
                throw new InvalidArgumentException(
                    message: "State '{$path}' cannot have both '@done' and 'target' with 'machine'. "
                           ."Use '@done' for managed delegation or 'target' for fire-and-forget."
                );
            }
        }

        // Validate @done.{state} coverage against child machine's final states
        self::validateChildFinalStateCoverage($stateConfig, $path);
    }

    /**
     * Validates job actor configuration.
     *
     * @throws InvalidArgumentException
     */
    private static function validateJobConfig(array $stateConfig, string $path): void
    {
        $jobClass = $stateConfig['job'];

        // job value must be a string (FQCN)
        if (!is_string($jobClass)) {
            throw new InvalidArgumentException(
                message: "State '{$path}' has invalid 'job' value. Must be a string (job class FQCN)."
            );
        }

        // job + machine are mutually exclusive
        if (isset($stateConfig['machine'])) {
            throw new InvalidArgumentException(
                message: "State '{$path}' cannot have both 'job' and 'machine'. Use one or the other."
            );
        }

        // job + type:parallel are mutually exclusive
        if (isset($stateConfig['type']) && $stateConfig['type'] === 'parallel') {
            throw new InvalidArgumentException(
                message: "State '{$path}' cannot have both 'job' and type 'parallel'."
            );
        }

        // forward is only valid for machine delegation, not jobs
        if (!empty($stateConfig['forward'])) {
            throw new InvalidArgumentException(
                message: "State '{$path}' has 'forward' with 'job'. Event forwarding is only valid for machine delegation."
            );
        }

        // Fire-and-forget: job without @done requires target
        if (!isset($stateConfig['@done']) && !isset($stateConfig['target'])) {
            throw new InvalidArgumentException(
                message: "State '{$path}' has 'job' without '@done' or 'target'. Either define '@done' (managed) or 'target' (fire-and-forget)."
            );
        }

        // Ambiguous: @done + target
        if (isset($stateConfig['@done']) && isset($stateConfig['target'])) {
            throw new InvalidArgumentException(
                message: "State '{$path}' cannot have both '@done' and 'target'. Use '@done' for managed jobs or 'target' for fire-and-forget."
            );
        }
    }

    /**
     * Normalizes the given value into an array or returns null.
     *
     * @throws InvalidArgumentException If the value is neither string, array, nor null.
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

        throw new InvalidArgumentException('Value must be string, array or null');
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
     * @throws InvalidArgumentException When child final states are uncovered.
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
            throw new InvalidArgumentException(
                message: "State '{$path}' has @done.{state} routing but child machine "
                    ."'{$machineClass}' has uncovered final states: "
                    .implode(', ', $uncovered)
                    .". Add specific '@done.{state}' keys or a catch-all '@done' to handle all outcomes."
            );
        }
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
