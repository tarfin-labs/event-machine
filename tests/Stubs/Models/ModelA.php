<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Models;

use Illuminate\Database\Eloquent\Model;
use Tarfinlabs\EventMachine\Traits\HasMachines;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AbcMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ElevatorMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

/**
 * Class ModelA.
 *
 * @property string $value
 * @property \Tarfinlabs\EventMachine\Actor\Machine $abc_mre
 * @property \Tarfinlabs\EventMachine\Actor\Machine $traffic_mre
 * @property \Tarfinlabs\EventMachine\Actor\Machine $elevator_mre
 */
class ModelA extends Model
{
    use HasFactory;
    use HasMachines;

    protected $fillable = [
        'value',
        'abc_mre',
        'traffic_mre',
        'elevator_mre',
    ];
    protected array $machines = [
        'abc_mre' => AbcMachine::class.':modelA',
    ];
    protected $casts = [
        'value'       => 'string',
        'traffic_mre' => TrafficLightsMachine::class.':modelA',
    ];

    protected function machines(): array
    {
        return [
            'elevator_mre' => ElevatorMachine::class.':modelA',
        ];
    }
}
