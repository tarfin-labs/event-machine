<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Casts;

use Illuminate\Database\Eloquent\Model;
use Tarfinlabs\EventMachine\Actor\MachineActor;
use Tarfinlabs\EventMachine\Definition\EventMachine;
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
    public function get(Model $model, string $key, mixed $value, array $attributes): MachineActor
    {
        /** @var EventMachine $machineClass */
        $machineClass = $model->getCasts()[$key];

        return $machineClass::start($value);
    }

    /**
     * Transform the attribute to its underlying model values.
     *
     * @param  TSet|null  $value
     * @param  array<mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return $value->state->history->first()->root_event_id;
    }
}
