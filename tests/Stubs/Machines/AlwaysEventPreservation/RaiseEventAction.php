<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysEventPreservation;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Raises a PROCEED event with payload.
 */
class RaiseEventAction extends ActionBehavior
{
    public function __invoke(): void
    {
        $this->raise([
            'type'    => 'PROCEED',
            'payload' => ['raised_key' => 'raised_value'],
        ]);
    }
}
