<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;

class TestEndpointOutput extends OutputBehavior
{
    public function __invoke(ContextManager $context): array
    {
        return [
            'custom'  => true,
            'context' => $context->toArray(),
        ];
    }
}
