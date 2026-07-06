<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Actions;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class RaisesArrayEventAction extends ActionBehavior
{
    public function __invoke(): void
    {
        $this->raise(['type' => 'ARRAY_RAISED', 'payload' => ['key' => 'value']]);
    }
}
