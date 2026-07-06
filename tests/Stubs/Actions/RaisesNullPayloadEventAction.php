<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Actions;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\PayloadedTestEvent;

class RaisesNullPayloadEventAction extends ActionBehavior
{
    public function __invoke(): void
    {
        $this->raise(new PayloadedTestEvent(payload: null));
    }
}
