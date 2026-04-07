<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Outputs;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;

class StateLevelTestOutput extends OutputBehavior
{
    public function __invoke(ContextManager $ctx): array
    {
        return ['computed' => $ctx->get('raw') * 2];
    }
}
