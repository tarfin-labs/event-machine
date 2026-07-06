<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Actions;

use Spatie\LaravelData\Optional;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\PayloadedTestEvent;

class RaisesOptionalPayloadEventAction extends ActionBehavior
{
    public function __invoke(): void
    {
        $this->raise(new PayloadedTestEvent(payload: Optional::create()));
    }
}
