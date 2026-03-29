<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Support;

use Closure;
use Tarfinlabs\EventMachine\Exceptions\InvalidBehaviorDefinitionException;

/**
 * Parses behavior tuple definitions: [ClassOrKey, 'param' => value, ...]
 * Extracts the class/key at position [0] and named config params (string keys, excluding @-prefixed).
 */
final class BehaviorTupleParser
{
    /**
     * Parse a behavior definition element from a behavior list.
     *
     * @param  mixed  $element  A single element from a behavior list (string, Closure, or array tuple)
     * @param  string  $context  Description of where this definition appears (for error messages)
     *
     * @return array{definition: string|Closure, configParams: array<string, mixed>}
     */
    public static function parse(mixed $element, string $context = 'behavior'): array
    {
        // String → class reference or inline key, no params
        if (is_string($element)) {
            return ['definition' => $element, 'configParams' => []];
        }

        // Closure → inline closure, no params
        if ($element instanceof Closure) {
            return ['definition' => $element, 'configParams' => []];
        }

        // Array → parameterized tuple
        return self::parseTuple((array) $element, $context);
    }

    /**
     * Parse an array tuple: [ClassOrKey, 'param' => value, ...].
     *
     * @param  array<mixed>  $tuple
     *
     * @return array{definition: string, configParams: array<string, mixed>}
     */
    private static function parseTuple(array $tuple, string $context): array
    {
        // Empty tuple
        if ($tuple === []) {
            throw InvalidBehaviorDefinitionException::emptyTuple($context);
        }

        // Must have [0] as class reference or inline key
        if (!array_key_exists(0, $tuple)) {
            throw InvalidBehaviorDefinitionException::missingClassAtZero($context);
        }

        $definition = $tuple[0];

        // Closure at [0] is not allowed
        if ($definition instanceof Closure) {
            throw InvalidBehaviorDefinitionException::closureInTuple($context);
        }

        // Extract config params: string keys, excluding @-prefixed (framework-reserved)
        $configParams = array_filter(
            $tuple,
            fn (int|string $k): bool => is_string($k) && !str_starts_with($k, '@'),
            ARRAY_FILTER_USE_KEY,
        );

        return ['definition' => $definition, 'configParams' => $configParams];
    }

    /**
     * Normalize a behavior key value into a list of elements.
     * Handles: single string, single Closure, array (list or single tuple).
     *
     * For list-type behavior keys (guards, actions, calculators, entry, exit):
     * - `'guardName'` → `['guardName']`
     * - `GuardClass::class` → `[GuardClass::class]`
     * - `fn() => true` → `[fn() => true]`
     * - `[GuardA::class, GuardB::class]` → `[GuardA::class, GuardB::class]` (list of behaviors)
     * - `[[GuardA::class, 'min' => 100]]` → `[[GuardA::class, 'min' => 100]]` (list with one tuple)
     *
     * @param  mixed  $value  Raw behavior key value from config
     *
     * @return array<int, mixed> Normalized list of behavior elements
     */
    public static function normalizeToList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        // Single string or Closure → wrap in list
        if (is_string($value) || $value instanceof Closure) {
            return [$value];
        }

        // Already an array → return as-is (it's a list of elements)
        if (is_array($value)) {
            return $value;
        }

        return [$value];
    }
}
