<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Actions;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\PayloadedTestEvent;

class RaisesInvalidPayloadedEventAction extends ActionBehavior
{
    public function __invoke(): void
    {
        $this->raise(PayloadedTestEvent::forTesting([
            'payload' => ['decision' => 'bogus'],
        ]));
    }
}
