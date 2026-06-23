<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Reads;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;

class ReadSummaryOutput extends OutputBehavior
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(ContextManager $context): array
    {
        return [
            'summary' => sprintf('%s:%s', $context->get('orderId'), $context->get('total')),
        ];
    }
}
