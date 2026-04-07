<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Outputs;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;

class ContextOnlyTestOutput extends OutputBehavior
{
    public function __invoke(ContextManager $ctx): array
    {
        return ['total' => $ctx->get('total')];
    }
}
