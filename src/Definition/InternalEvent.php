<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

enum InternalEvent: string
{
    case MACHINE_INIT = 'machine.init';
}
