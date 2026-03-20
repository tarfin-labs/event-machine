<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Builders;

use Tarfinlabs\EventMachine\Testing\EventBuilder;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\SimpleEvent;

class SimpleEventBuilder extends EventBuilder
{
    protected function eventClass(): string
    {
        return SimpleEvent::class;
    }

    protected function definition(): array
    {
        return [
            'type'    => SimpleEvent::getType(),
            'payload' => [
                'name'  => $this->faker->name(),
                'value' => $this->faker->numberBetween(1, 100),
            ],
            'version' => 1,
        ];
    }

    public function withValue(int $value): static
    {
        return $this->state(['payload' => ['value' => $value]]);
    }

    public function withName(string $name): static
    {
        return $this->state(['payload' => ['name' => $name]]);
    }
}
