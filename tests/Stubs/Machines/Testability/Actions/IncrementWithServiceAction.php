<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\Actions;

use Illuminate\Support\Collection;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\CounterService;

class IncrementWithServiceAction extends ActionBehavior
{
    public function __construct(
        private readonly CounterService $counterService,
        ?Collection $eventQueue = null,
    ) {
        parent::__construct($eventQueue);
    }

    public function __invoke(ContextManager $context): void
    {
        $context->set('count', $this->counterService->increment($context->get('count')));
    }
}
