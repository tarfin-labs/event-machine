<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Models;

use Illuminate\Database\Eloquent\Model;
use Tarfinlabs\EventMachine\Traits\HasMachines;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AbcMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

/**
 * Class ModelA.
 *
 * @property string $value
 * @property \Tarfinlabs\EventMachine\Actor\Machine $abc_mre
 * @property \Tarfinlabs\EventMachine\Actor\Machine $traffic_mre
 */
class ModelA extends Model
{
    use HasFactory;
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
