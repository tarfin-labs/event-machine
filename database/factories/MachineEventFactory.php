<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Database\Factories;

use Symfony\Component\Uid\Ulid;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Class MachineEventFactory.
 *
 * The MachineEventFactory class is responsible for generating machine event data for testing purposes.
 * It extends the Factory class and uses the MachineEvent model.
 *
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
            'machine_value' => [
                $this->faker->word().'_state',
            ],
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
