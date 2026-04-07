<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Outputs;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;

class DoubleAmountOutput extends OutputBehavior
{
    public function __invoke(ContextManager $ctx): array
    {
        return ['doubled' => $ctx->get('amount') * 2];
    }
}
