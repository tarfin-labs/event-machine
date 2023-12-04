<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

abstract class ResultBehavior extends InvokableBehavior
{
    public function __invoke(): mixed
    {
        return parent::__invoke();
    }
}
