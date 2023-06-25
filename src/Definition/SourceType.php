<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

enum SourceType: string
{
    case INTERNAL = 'internal';
    case EXTERNAL = 'external';
}
