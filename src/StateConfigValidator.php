<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

use InvalidArgumentException;

class StateConfigValidator
{
    /** Allowed keys at different levels of the machine configuration */
    private const ALLOWED_ROOT_KEYS = [
        'id', 'version', 'initial', 'context', 'states', 'on', 'type',
        'meta', 'entry', 'exit', 'description', 'scenarios_enabled',
        'should_persist', 'delimiter',
    ];

    private const ALLOWED_STATE_KEYS = [
        'on', 'states', 'initial', 'type', 'meta', 'entry', 'exit', 'description',
    ];

    private const ALLOWED_TRANSITION_KEYS = [
        'target', 'guards', 'actions', 'description', 'calculators',
    ];

    /** Valid state types matching StateDefinitionType enum */
    private const VALID_STATE_TYPES = [
        'atomic', 'compound', 'final',
    ];

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
     * Validates the machine configuration structure.
     *
     * @throws InvalidArgumentException
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
            self::validateTransitionsConfig(transitionsConfig: $config['on'], path: 'root');
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

        if (!empty($invalidRootKeys)) {
            throw new InvalidArgumentException(
                message: 'Invalid root level configuration keys: '.implode(separator: ', ', array: $invalidRootKeys).
                '. Allowed keys are: '.implode(separator: ', ', array: self::ALLOWED_ROOT_KEYS)
            );
        }
    }

    /**
     * Validates a single state's configuration.
     *
     * @throws InvalidArgumentException
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
        if (!empty($invalidKeys)) {
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

        // Validate nested states
        if (isset($stateConfig['states'])) {
            if (!is_array($stateConfig['states'])) {
                throw new InvalidArgumentException(
                    message: "State '{$path}' has invalid states configuration. States must be an array."
                );
            }

            foreach ($stateConfig['states'] as $childKey => $childState) {
                self::validateStateConfig(stateConfig: $childState, path: "{$path}.{$childKey}");
            }
        }

        // Validate transitions under 'on'
        if (isset($stateConfig['on'])) {
            self::validateTransitionsConfig(transitionsConfig: $stateConfig['on'], path: $path);
        }
    }
}
