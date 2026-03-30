<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class MultiplyByParamAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, int $factor): void
    {
        $context->set('total', $context->get('total') * $factor);
    }
}
