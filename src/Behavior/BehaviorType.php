<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

enum BehaviorType: string
{
    case Guard  = 'guards';
    case Action = 'actions';
    case Event  = 'events';
}
