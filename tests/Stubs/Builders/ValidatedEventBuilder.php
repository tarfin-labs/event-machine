<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Builders;

use Tarfinlabs\EventMachine\Testing\EventBuilder;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\ValidatedEvent;

class ValidatedEventBuilder extends EventBuilder
{
    protected function eventClass(): string
    {
        return ValidatedEvent::class;
    }

    protected function definition(): array
    {
        return [
            'type'    => ValidatedEvent::getType(),
            'payload' => [
                'attribute' => $this->faker->numberBetween(1, 10),
                'value'     => $this->faker->numberBetween(1, 10),
            ],
            'version' => 1,
        ];
    }

    public function withInvalidAttribute(): static
    {
        return $this->state(['payload' => ['attribute' => 999]]);
    }
}
