<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

enum TransitionType: string
{
    case Normal = '@normal';
    case Always = '@always';
}
