<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Casts;

use Illuminate\Database\Eloquent\Model;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Exceptions\RestoringStateException;
use Tarfinlabs\EventMachine\Exceptions\BehaviorNotFoundException;

/**
 * Eloquent cast for static (single-class) machine attributes.
 *
 * Returns a PHP 8.4 lazy proxy on read — the real Machine is only
 * restored from the database when a property or method is first accessed.
 *
 * @template TGet
 * @template TSet
 */
class MachineCast extends MachineBaseCast
{
    public function __construct(
        protected string $machineClass,
        protected string $contextKey,
    ) {}

    /**
     * Return a lazy proxy — zero cost, no DB query.
     *
     * The real Machine is only restored when a property/method is accessed.
     * Laravel's $classCastCache prevents repeated get() calls.
     *
     * @param  array<mixed>  $attributes
     *
     * @throws BehaviorNotFoundException
     * @throws RestoringStateException
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Machine
    {
        if ($value === null) {
            return null;
        }

        return $this->createLazyProxy($this->machineClass, $value, $model, $this->contextKey);
    }
}
