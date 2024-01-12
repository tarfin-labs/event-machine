<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Actions;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Models\ModelA;

class SAction extends ActionBehavior
{
    public function __invoke(): void
    {
        ModelA::first()->update([
            'value' => 'new value',
        ]);
    }
}
