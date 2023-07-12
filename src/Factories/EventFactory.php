<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

abstract class EventFactory extends Factory
{
    public function newModel(array $attributes = [])
    {
        $model = $this->modelName();

        return new $model(...$attributes);
    }
}
