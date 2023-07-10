<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Models;

use Illuminate\Database\Eloquent\Model;
use Tarfinlabs\EventMachine\Traits\HasMachines;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AbcMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

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
        'abc_mre'     => AbcMachine::class,
        'traffic_mre' => TrafficLightsMachine::class,
    ];
}
