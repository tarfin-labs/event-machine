<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Casts;

use ReflectionClass;
use Illuminate\Database\Eloquent\Model;
use Tarfinlabs\EventMachine\Actor\Machine;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;

/**
 * @template TGet
 * @template TSet
 *
 * @implements CastsAttributes<Machine|null, Machine|string|null>
 */
abstract class MachineBaseCast implements CastsAttributes, SerializesCastableAttributes
{
    /**
     * Extract root_event_id for DB storage.
     *
     * If $value is an uninitialized lazy proxy, returns the existing raw
     * value from $attributes to avoid triggering unnecessary machine
     * restoration. This happens during mergeAttributesFromCachedCasts()
     * (called by getAttributes(), save(), getDirty(), etc.) when the
     * proxy was cached by toArray() but never actually used.
     *
     * @param  array<mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if ((new ReflectionClass($value))->isUninitializedLazyObject($value)) {
            return $attributes[$key] ?? null;
        }

        return $value->state->history->first()->root_event_id;
    }

    /**
     * Serialize as raw root_event_id for toArray()/toJson().
     * Reads from $attributes (raw DB values) — never touches the lazy proxy.
     *
     * @param  array<mixed>  $attributes
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $attributes[$key] ?? null;
    }

    /**
     * Create a lazy proxy that defers machine restoration until first use.
     *
     * The proxy is a real Machine instance (passes instanceof checks) but
     * its factory is only invoked when a property or method is accessed.
     */
    protected function createLazyProxy(string $machineClass, string $value, Model $model, string $contextKey): Machine
    {
        return (new ReflectionClass(Machine::class))->newLazyProxy(
            static function () use ($machineClass, $value, $model, $contextKey): Machine {
                $machine = $machineClass::create(state: $value);
                $machine->state->context->set($contextKey, $model);

                return $machine;
            }
        );
    }
}
