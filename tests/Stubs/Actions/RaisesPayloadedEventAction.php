<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Actions;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\PayloadedTestEvent;

class RaisesPayloadedEventAction extends ActionBehavior
{
    public function __invoke(): void
    {
        $this->raise(PayloadedTestEvent::forTesting([
            'payload' => [
                'decision' => 'approved',
                'nested'   => ['level' => 1, 'tags' => ['a', 'b']],
            ],
        ]));
    }
}
