<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Traits;

use Illuminate\Database\Eloquent\Model;
use Tarfinlabs\EventMachine\Actor\Machine;

/**
 * Trait HasMachines.
 *
 * This trait provides functionality for initializing
 * machines before creating a model instance.
 */
trait HasMachines
{
    /**
     * Boot method for ensuring that machines have been
     * initialized before creating a model instance.
     */
    protected static function bootHasMachines(): void
    {
        static::creating(static function (Model $model): void {
            foreach ($model->getCasts() as $attribute => $cast) {
                if (
                    !isset($model->attributes[$attribute]) &&
                    is_subclass_of(explode(':', $cast)[0], Machine::class) &&
                    $model->shouldInitializeMachine()
                ) {
                    $machine = $model->$attribute;

                    $model->attributes[$attribute] = $machine->state->history->first()->root_event_id;
                }
            }
        });
    }

    public function getAttribute($key)
    {
        $attribute = parent::getAttribute($key);

        if ($this->shouldInitializeMachine() === true) {

            $machine = $this->findMachine($key);

            if ($machine !== null) {
                /** @var \Tarfinlabs\EventMachine\Actor\Machine $machineClass */
                [$machineClass, $contextKey] = explode(':', $machine);

                $machine = $machineClass::create(state: $attribute);

                $machine->state->context->set($contextKey, $this);

                return $machine;
            }
        }

        return $attribute;
    }

    /**
     * Determines whether the machine should be initialized.
     *
     * @return bool Returns true if the machine should be initialized, false otherwise.
     */
    public function shouldInitializeMachine(): bool
    {
        return true;
    }

    /**
     * Checks if the machine configuration exists for the given key
     * either in the `machines` method or the `machines` property of the model.
     *
     * @param $key
     * @return string|null
     */
    private function findMachine($key): ?string
    {
        if (method_exists($this, 'machines')) {
            $machines = $this->machines();

            if (array_key_exists($key, $machines)) {
                return $machines[$key];
            }
        }

        if (property_exists($this, 'machines')) {
            $machines = $this->machines;

            if (array_key_exists($key, $machines)) {
                return $machines[$key];
            }
        }

        return null;
    }
}
