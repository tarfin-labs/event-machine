<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Traits;

use Illuminate\Database\Eloquent\Model;
use Tarfinlabs\EventMachine\Definition\EventMachine;

trait HasMachines
{
    protected static function bootHasMachines(): void
    {
        static::creating(function (Model $model): void {
            foreach ($model->getCasts() as $attribute => $cast) {
                if (
                    !isset($model->attributes[$attribute]) &&
                    is_subclass_of(explode(':', $cast)[0], EventMachine::class) &&
                    $model->shouldInitializeMachine()
                ) {
                    $machine = $model->$attribute;

                    $model->attributes[$attribute] = $machine->state->history->first()->root_event_id;
                }
            }
        });
    }

    public function shouldInitializeMachine(): bool
    {
        return true;
    }
}
