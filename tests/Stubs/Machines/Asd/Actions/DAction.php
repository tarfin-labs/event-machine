<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Actions;

use RuntimeException;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class DAction extends ActionBehavior
{
    public function __invoke(): void
    {
        throw new RuntimeException('error');
    }
}
