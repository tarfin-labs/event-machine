<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;

abstract class EventFactory extends Factory
{
    /**
     * Get a new model instance.
     *
     * @param  array<mixed>  $attributes
     */
    public function newModel(array $attributes = []): EventBehavior
    {
        /** @var EventBehavior $model */
        $model = $this->modelName();

        return $model::from($attributes);
    }
}
