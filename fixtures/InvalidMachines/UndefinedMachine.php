<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Fixtures\InvalidMachines;

use Tarfinlabs\EventMachine\Actor\Machine;

/**
 * A Machine subclass that never defines a machine.
 *
 * `Machine::definition()` is declared as a thrower, so this class passes every
 * structural check a command can make — class_exists, is_subclass_of — and then
 * raises MachineDefinitionNotFoundException from inside the call. Abstract and
 * half-written machines in a real codebase have exactly this shape.
 *
 * It lives in fixtures/ rather than tests/Stubs/ for the same reason its neighbours
 * do: machine:validate --all sweeps the stub tree, and a machine that cannot produce
 * a definition would fail that sweep.
 */
class UndefinedMachine extends Machine {}
