<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Casts;

use Illuminate\Database\Eloquent\Model;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Traits\HasMachines;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Tarfinlabs\EventMachine\Exceptions\RestoringStateException;
use Tarfinlabs\EventMachine\Exceptions\BehaviorNotFoundException;

/**
 * @template TGet
 * @template TSet
 */
class MachineCast implements CastsAttributes
{
    /**
     * Transform the attribute from the underlying model values.
     *
     * @param  array<mixed>  $attributes
     *
     * @throws BehaviorNotFoundException
     * @throws RestoringStateException
     */
    public function get(
        Model $model,
        string $key,
        mixed $value,
        array $attributes
    ): ?Machine {
        if (
            in_array(HasMachines::class, class_uses($model), true) &&
            $model->shouldInitializeMachine() === false
        ) {
            return null;
        }

        /** @var \Tarfinlabs\EventMachine\Actor\Machine $machineClass */
        [$machineClass, $contextKey] = explode(':', $model->getCasts()[$key]);

        $machine = $machineClass::create(state: $value);

        $machine->state->context->set($contextKey, $model);

        return $machine;
    }

    /**
     * Transform the attribute to its underlying model values.
     *
     * @param  TSet|null  $value
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

        return $value->state->history->first()->root_event_id;
    }
}
