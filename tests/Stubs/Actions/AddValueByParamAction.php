<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class AddValueByParamAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, int $value): void
    {
        $context->set('total', $context->get('total') + $value);
    }
}
