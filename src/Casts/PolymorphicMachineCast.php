<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Casts;

use Illuminate\Database\Eloquent\Model;
use Tarfinlabs\EventMachine\Actor\Machine;

/**
 * Eloquent cast for polymorphic machine attributes.
 *
 * Resolves the machine class at runtime via a model method or
 * a raw attribute value, then returns a lazy proxy.
 *
 * Usage in model casts():
 *   'mre' => PolymorphicMachineCast::class . ':machineResolver,context_key'
 *
 * Resolution strategies (tried in order):
 *   1. Model method: if machineResolver() method exists → call it
 *   2. Raw attribute: read $attributes['machineResolver'] as FQCN
 */
class PolymorphicMachineCast extends MachineBaseCast
{
    public function __construct(
        protected string $resolverKey,
        protected string $contextKey,
    ) {}

    /**
     * Resolve machine class, return lazy proxy.
     *
     * The resolver IS called during toArray() (inside get()), but this is
     * cheap — typically a match expression reading raw attributes. The
     * expensive machine restoration is still deferred by the lazy proxy.
     *
     * @param  array<mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Machine
    {
        if ($value === null) {
            return null;
        }

        $machineClass = $this->resolveMachineClass($model, $attributes);

        if ($machineClass === null) {
            return null;
        }

        return $this->createLazyProxy($machineClass, $value, $model, $this->contextKey);
    }

    /**
     * Resolve the machine FQCN — cheap operation, no DB query.
     *
     * @param  array<mixed>  $attributes
     */
    private function resolveMachineClass(Model $model, array $attributes): ?string
    {
        // Strategy 1: Model method (e.g., machineResolver())
        if (method_exists($model, $this->resolverKey)) {
            return $model->{$this->resolverKey}();
        }

        // Strategy 2: Raw attribute value (e.g., 'machine_type' column stores FQCN)
        return $attributes[$this->resolverKey] ?? null;
    }
}
