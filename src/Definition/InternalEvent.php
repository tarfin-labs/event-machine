<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

enum InternalEvent: string
{
    case MACHINE_INIT = 'machine.init';
    case ACTION_INIT  = 'machine.action.%s.init';
    case ACTION_DONE  = 'machine.action.%s.done';
    case GUARD_INIT   = 'machine.guard.%s.init';
    case GUARD_FAIL   = 'machine.guard.%s.fail';
    case GUARD_PASS   = 'machine.guard.%s.pass';
}
