<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Database\Factories;

use Symfony\Component\Uid\Ulid;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @template TModel of MachineEvent
 */
class MachineEventFactory extends Factory
{
    protected $model = MachineEvent::class;

    public function definition(): array
    {
        return [
            'id'              => Ulid::generate(),
            'sequence_number' => $this->faker->numberBetween(1, 100),
            'created_at'      => $this->faker->dateTime(),
            'machine_id'      => implode(
                separator: '_',
                array: $this->faker->words($this->faker->numberBetween(1, 3))
            ).'_machine',
            'type' => mb_strtoupper(
                implode(
                    separator: '_',
                    array: $this->faker->words($this->faker->numberBetween(1, 3))
                ).'_EVENT',
            ),
            'payload' => [
                $this->faker->word(),
                $this->faker->word(),
            ],
            'version' => $this->faker->numberBetween(1, 100),
            'context' => [
                $this->faker->word(),
                $this->faker->word(),
            ],
        ];
    }
}
