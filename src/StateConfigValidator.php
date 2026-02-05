<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

use InvalidArgumentException;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class StateConfigValidator
{
    /** Allowed keys at different levels of the machine configuration */
    private const ALLOWED_ROOT_KEYS = [
        'id', 'version', 'initial', 'status_events', 'context', 'states', 'on', 'type',
        'meta', 'entry', 'exit', 'description', 'scenarios_enabled',
        'should_persist', 'delimiter',
    ];

    private const ALLOWED_STATE_KEYS = [
        'id', 'on', 'states', 'initial', 'type', 'meta', 'entry', 'exit', 'description', 'result', 'onDone',
    ];

    private const ALLOWED_TRANSITION_KEYS = [
        'target', 'guards', 'actions', 'description', 'calculators',
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

        // Validate state keys
        $invalidKeys = array_diff(array_keys($stateConfig), self::ALLOWED_STATE_KEYS);
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
            throw new InvalidArgumentException(
                message: "Parallel state '{$path}' cannot have an 'initial' property. ".
                'All regions are entered simultaneously.'
            );
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

        // If it's an array of conditions (guarded transitions)
        if (array_is_list($transition)) {
            self::validateGuardedTransitions($transition, $path, $eventName);
            foreach ($transition as &$condition) {
                self::validateTransitionConfig(transitionConfig: $condition, path: $path, eventName: $eventName);
            }

            return;
        }

        self::validateTransitionConfig(transitionConfig: $transition, path: $path, eventName: $eventName);
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
}
