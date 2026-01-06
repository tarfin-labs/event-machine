<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

use Illuminate\Database\Eloquent\Collection;
use Tarfinlabs\EventMachine\Models\MachineEvent;

/**
 * @extends Collection<int, MachineEvent>
 *
 * @method MachineEvent|null first(callable $callback = null, $default = null)
 * @method MachineEvent|null last(callable $callback = null, $default = null)
 * @method MachineEvent|null get(int $key, $default = null)
 */
class EventCollection extends Collection {}
