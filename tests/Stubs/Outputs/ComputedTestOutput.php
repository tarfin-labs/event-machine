<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Outputs;

use Tarfinlabs\EventMachine\Behavior\OutputBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\ComputedContextManager;

class ComputedTestOutput extends OutputBehavior
{
    public function __invoke(ComputedContextManager $ctx): array
    {
        return ['computedTotal' => $ctx->subtotal + $ctx->tax];
    }
}
