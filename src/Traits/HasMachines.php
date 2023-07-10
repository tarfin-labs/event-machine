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
                    is_subclass_of($cast, EventMachine::class) &&
                    !isset($model->attributes[$attribute])
                ) {
                    $machine = $model->$attribute;

                    $model->attributes[$attribute] = $machine->state->history->first()->root_event_id;
                }
            }
        });
    }
}
