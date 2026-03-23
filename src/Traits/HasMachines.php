<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Traits;

use Illuminate\Database\Eloquent\Model;
use Tarfinlabs\EventMachine\Actor\Machine;

/**
 * Trait HasMachines.
 *
 * Provides auto-initialization of machine casts on model creation
 * and helper methods for working with machine attributes without
 * triggering lazy proxy initialization.
 *
 * v9: No getAttribute() override — zero overhead on non-machine attributes.
 * Machine definitions are read exclusively from $casts via Castable interface.
 */
trait HasMachines
{
    /**
     * Auto-initialize machines when model is being created.
     *
     * Only initializes static (non-polymorphic) machine casts — those
     * where the cast class is a Machine subclass. PolymorphicMachineCast
     * is naturally excluded because it is not a Machine subclass.
     */
    protected static function bootHasMachines(): void
    {
        static::creating(static function (Model $model): void {
            foreach ($model->getCasts() as $attribute => $cast) {
                if (isset($model->attributes[$attribute])) {
                    continue;
                }

                $castClass = is_string($cast) ? explode(':', $cast)[0] : null;

                if ($castClass !== null && is_subclass_of($castClass, Machine::class)) {
                    $machine = $castClass::create();
                    $machine->persist();

                    $model->attributes[$attribute] = $machine->state->history->first()->root_event_id;
                }
            }
        });
    }

    /**
     * Force re-restore a machine from the database.
     *
     * Clears the cached lazy proxy so the next attribute access
     * creates a fresh proxy whose factory will query the DB.
     */
    public function refreshMachine(string $attribute): Machine
    {
        unset($this->classCastCache[$attribute]);

        return $this->getAttribute($attribute);
    }

    /**
     * Get raw root_event_id without triggering machine restore.
     *
     * Uses $this->attributes directly instead of getAttributes()
     * to avoid triggering mergeAttributesFromCachedCasts().
     */
    public function getMachineId(string $attribute): ?string
    {
        return $this->getRawOriginal($attribute)
            ?? $this->attributes[$attribute]
            ?? null;
    }

    /**
     * Check if a machine attribute has been initialized (has a root_event_id).
     *
     * Does NOT trigger machine restore.
     */
    public function hasMachine(string $attribute): bool
    {
        return $this->getMachineId($attribute) !== null;
    }
}
