<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Outputs;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;

class FormatOutput extends OutputBehavior
{
    public function __invoke(ContextManager $context, string $format): array
    {
        return [
            'format' => $format,
            'total'  => $context->get('total'),
        ];
    }
}
