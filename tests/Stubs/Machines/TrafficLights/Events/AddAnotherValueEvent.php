<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class AddAnotherValueEvent extends EventBehavior
{
    public function __construct(
        public int $value,
    ) {
        parent::__construct();
    }

    public static function getType(): string
    {
        return 'ADD2';
    }
}
