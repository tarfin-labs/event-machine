<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Outputs;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;

class ParallelSummaryOutput extends OutputBehavior
{
    public function __invoke(ContextManager $ctx): array
    {
        return [
            'regionAData'    => $ctx->get('regionAData'),
            'regionBData'    => $ctx->get('regionBData'),
            'combinedStatus' => ($ctx->get('regionAData') !== null && $ctx->get('regionBData') !== null)
                ? 'both_complete'
                : 'in_progress',
        ];
    }
}
