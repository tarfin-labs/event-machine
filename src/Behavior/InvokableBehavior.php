<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Tarfinlabs\EventMachine\ContextManager;

interface InvokableBehavior
{
    public function __invoke(ContextManager $context, array $event);
}
