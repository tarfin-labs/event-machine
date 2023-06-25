<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

enum InternalEvent: string
{
    case MACHINE_INIT = 'machine.init';
    case ACTION_INIT  = 'machine.action.%s.init';
    case ACTION_DONE  = 'machine.action.%s.done';
}
