<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Models;

use Illuminate\Database\Eloquent\Model;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Traits\HasMachines;
use Tarfinlabs\EventMachine\Casts\PolymorphicMachineCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AbcMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ElevatorMachine;

/**
 * Class ModelB — polymorphic machine testing.
 *
 * @property string $value
 * @property string $machine_type
 * @property Machine $status_mre
 */
class ModelB extends Model
{
    use HasFactory;
    use HasMachines;

    protected $fillable = [
        'value',
        'machine_type',
        'status_mre',
    ];

    protected function casts(): array
    {
        return [
            'value'      => 'string',
            'status_mre' => PolymorphicMachineCast::class.':machineResolver,modelB',
        ];
    }

    /**
     * Resolve machine class based on machine_type attribute.
     */
    public function machineResolver(): string
    {
        return match ($this->getRawOriginal('machine_type')) {
            'elevator' => ElevatorMachine::class,
            default    => AbcMachine::class,
        };
    }
}
