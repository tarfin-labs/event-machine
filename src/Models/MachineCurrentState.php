<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Models;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tracks the current state of each machine instance.
 *
 * Normalized: one row per active state per instance.
 * Simple machines have 1 row, parallel machines have N rows (one per region).
 *
 * @property string $root_event_id The machine instance's root_event_id.
 * @property string $machine_class The FQCN of the machine.
 * @property string $state_id The current state ID.
 * @property Carbon $state_entered_at When the instance entered this state.
 */
class MachineCurrentState extends Model
{
    public $timestamps   = false;
    public $incrementing = false;
    protected $table     = 'machine_current_states';

    /**
     * Composite PK (root_event_id, state_id) — Eloquent find() is unsupported, use query scopes.
     *
     * @var string
     */
    protected $primaryKey = 'root_event_id';

    public $keyType     = 'string';
    protected $fillable = [
        'root_event_id',
        'machine_class',
        'state_id',
        'state_entered_at',
    ];
    protected $casts = [
        'state_entered_at' => 'datetime',
    ];

    /**
     * Scope: instances of a specific machine class in a specific state.
     */
    public function scopeForSweep(Builder $query, string $machineClass, string $stateId): Builder
    {
        return $query
            ->where('machine_class', $machineClass)
            ->where('state_id', $stateId);
    }

    /**
     * Scope: instances that entered the state before a given deadline.
     */
    public function scopePastDeadline(Builder $query, Carbon $deadline): Builder
    {
        return $query->where('state_entered_at', '<=', $deadline);
    }

    /**
     * Scope: all states for a specific instance.
     */
    public function scopeForInstance(Builder $query, string $rootEventId): Builder
    {
        return $query->where('root_event_id', $rootEventId);
    }
}
