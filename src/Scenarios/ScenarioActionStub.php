<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

use Illuminate\Support\Collection;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class ScenarioActionStub extends ActionBehavior
{
    public function __construct(
        private readonly string $originalClass,
        private readonly array $stubData,
        ?Collection $eventQueue = null,
    ) {
        parent::__construct($eventQueue);
    }

    public function __invoke(State $state): void
    {
        $original = resolve($this->originalClass);

        if ($original instanceof ScenarioStubContract) {
            $original->applyStub($state, $this->stubData);

            return;
        }

        // Convention-based fallback: stub data keys map to context keys
        foreach ($this->stubData as $key => $value) {
            $state->context->set($key, $value);
        }
    }
}
