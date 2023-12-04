<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Actions;

use Closure;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Models\ModelA;

class AAction extends ActionBehavior
{
    public function definition(): Closure
    {
        return function (): void {
            ModelA::create([
                'value' => 'lorem ipsum dolor',
            ]);
        };
    }
}
