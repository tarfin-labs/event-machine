<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Models;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tracks timer fire events for deduplication and recurring timer state.
 *
 * - after timers: status='fired' prevents re-firing (one-shot guarantee)
 * - every timers: tracks last_fired_at and fire_count for interval/max logic
 * - every max/then: status='exhausted' prevents re-sending then event
 *
 * @property string $root_event_id The machine instance's root_event_id.
 * @property string $timer_key Format: {state_id}:{event_type}:{total_seconds}
 * @property Carbon $last_fired_at When the timer last fired.
 * @property int $fire_count How many times the timer has fired.
 * @property string $status active|fired|exhausted
 */
class MachineTimerFire extends Model
{
    public $timestamps   = false;
    public $incrementing = false;
    protected $table     = 'machine_timer_fires';

    /**
     * Composite PK (root_event_id, timer_key) — Eloquent find() is unsupported, use query scopes.
     *
     * @var string
     */
    protected $primaryKey = 'root_event_id';

    public $keyType = 'string';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FIRED = 'fired';

    public const STATUS_EXHAUSTED = 'exhausted';

    protected $fillable = [
        'root_event_id',
        'timer_key',
        'last_fired_at',
        'fire_count',
        'status',
    ];
    protected $casts = [
        'last_fired_at' => 'datetime',
        'fire_count'    => 'integer',
    ];

    /**
     * Check if this timer has already been fired (one-shot).
     */
    public function isFired(): bool
    {
        return $this->status === self::STATUS_FIRED;
    }

    /**
     * Check if this timer has been exhausted (max reached, then sent).
     */
    public function isExhausted(): bool
    {
        return $this->status === self::STATUS_EXHAUSTED;
    }

    /**
     * Scope: find a specific timer record for an instance.
     */
    public function scopeForTimer(Builder $query, string $rootEventId, string $timerKey): Builder
    {
        return $query
            ->where('root_event_id', $rootEventId)
            ->where('timer_key', $timerKey);
    }
}
