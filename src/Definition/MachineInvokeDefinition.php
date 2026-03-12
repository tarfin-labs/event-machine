<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use Closure;
use Tarfinlabs\EventMachine\ContextManager;

/**
 * Value object that holds machine delegation configuration.
 *
 * Parsed from the `machine` key in a state definition config.
 * Handles context transfer resolution via the `with` key.
 */
class MachineInvokeDefinition
{
    /**
     * @param  string  $machineClass  The FQCN of the child machine definition.
     * @param  array|Closure|null  $with  Context transfer configuration (3 formats).
     * @param  array  $forward  Event types to forward to the child machine.
     * @param  bool  $async  Whether the child runs asynchronously.
     * @param  string|null  $queue  Queue name for async execution.
     * @param  string|null  $connection  Queue connection for async execution.
     * @param  int|null  $timeout  Timeout in seconds for @timeout handling.
     * @param  int|null  $retry  Number of retry attempts for async execution.
     */
    public function __construct(
        public readonly string $machineClass,
        public readonly array|Closure|null $with = null,
        public readonly array $forward = [],
        public readonly bool $async = false,
        public readonly ?string $queue = null,
        public readonly ?string $connection = null,
        public readonly ?int $timeout = null,
        public readonly ?int $retry = null,
    ) {}

    /**
     * Resolve the child machine's initial context from the parent context.
     *
     * Supports 3 formats for the `with` key:
     * - Format 1: Same-name array ['order_id'] → child gets order_id from parent
     * - Format 2: Key mapping ['amount' => 'total_amount'] → child amount = parent total_amount
     * - Format 3: Closure fn(ContextManager $ctx) => ['key' => 'value']
     *
     * @param  ContextManager  $parentContext  The parent machine's context.
     *
     * @return array The resolved child context data.
     */
    public function resolveChildContext(ContextManager $parentContext): array
    {
        if ($this->with === null) {
            return [];
        }

        if ($this->with instanceof Closure) {
            return ($this->with)($parentContext);
        }

        $childContext = [];

        foreach ($this->with as $key => $value) {
            if (is_int($key)) {
                // Format 1: ['order_id'] → same name
                $childContext[$value] = $parentContext->get($value);
            } else {
                // Format 2: ['amount' => 'total_amount'] → rename
                $childContext[$key] = $parentContext->get($value);
            }
        }

        return $childContext;
    }
}
