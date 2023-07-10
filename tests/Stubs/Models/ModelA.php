<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Models;

use Illuminate\Database\Eloquent\Model;
use Tarfinlabs\EventMachine\Actor\MachineActor;
use Tarfinlabs\EventMachine\Traits\HasMachines;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AbcMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

/**
 * Class ModelA.
 *
 * @property string $value
 * @property MachineActor $abc_mre
 * @property MachineActor $traffic_mre
 */
class ModelA extends Model
{
    use HasMachines;

    protected $fillable = [
        'value',
        'abc_mre',
        'traffic_mre',
    ];
    protected $casts = [
        'value'       => 'string',
        'abc_mre'     => AbcMachine::class.':modelA',
        'traffic_mre' => TrafficLightsMachine::class.':modelA',
    ];
}
