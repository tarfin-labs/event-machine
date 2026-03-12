<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Models;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * Class MachineChild.
 *
 * Tracks async child machine instances for parent-child delegation.
 * Only used in async mode (when `queue` is set on the machine key).
 *
 * @property string $id ULID primary key.
 * @property string $parent_root_event_id The parent machine's root_event_id.
 * @property string $parent_state_id The parent state that invoked this child.
 * @property string $child_machine_class The FQCN of the child machine.
 * @property string|null $child_root_event_id The child machine's root_event_id (set after creation).
 * @property string $status Current status: pending, running, completed, failed, cancelled, timed_out.
 * @property Carbon $created_at When the child was created.
 * @property Carbon|null $completed_at When the child completed/failed/cancelled.
 */
class MachineChild extends Model
{
    use HasUlids;

    public $timestamps = false;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_TIMED_OUT = 'timed_out';

    protected $table    = 'machine_children';
    protected $fillable = [
        'id',
        'parent_root_event_id',
        'parent_state_id',
        'child_machine_class',
        'child_root_event_id',
        'status',
        'created_at',
        'completed_at',
    ];
    protected $casts = [
        'id'                   => 'string',
        'parent_root_event_id' => 'string',
        'parent_state_id'      => 'string',
        'child_machine_class'  => 'string',
        'child_root_event_id'  => 'string',
        'status'               => 'string',
        'created_at'           => 'datetime',
        'completed_at'         => 'datetime',
    ];

    // region Scopes

    /**
     * Scope to filter by parent machine.
     */
    protected function scopeForParent(Builder $query, string $parentRootEventId): Builder
    {
        return $query->where('parent_root_event_id', $parentRootEventId);
    }

    /**
     * Scope to filter by child machine.
     */
    protected function scopeForChild(Builder $query, string $childRootEventId): Builder
    {
        return $query->where('child_root_event_id', $childRootEventId);
    }

    /**
     * Scope to filter by status.
     */
    protected function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter active (non-terminal) children.
     */
    protected function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_RUNNING]);
    }

    // endregion

    // region Status Helpers

    /**
     * Check if this child is in a terminal state.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_TIMED_OUT,
        ], true);
    }

    /**
     * Mark this child as completed.
     */
    public function markCompleted(): void
    {
        $this->update([
            'status'       => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark this child as failed.
     */
    public function markFailed(): void
    {
        $this->update([
            'status'       => self::STATUS_FAILED,
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark this child as cancelled.
     */
    public function markCancelled(): void
    {
        $this->update([
            'status'       => self::STATUS_CANCELLED,
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark this child as timed out.
     */
    public function markTimedOut(): void
    {
        $this->update([
            'status'       => self::STATUS_TIMED_OUT,
            'completed_at' => now(),
        ]);
    }

    // endregion
}
