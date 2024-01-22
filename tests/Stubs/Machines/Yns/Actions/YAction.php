<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Yns\Actions;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Models\ModelA;

class YAction extends ActionBehavior
{
    public function __invoke(): void
    {
        ModelA::create([
            'value' => 'lorem ipsum dolor',
        ]);
    }
}
