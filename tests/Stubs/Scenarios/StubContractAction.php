<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Scenarios;

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Scenarios\ScenarioStubContract;

/**
 * Action that implements ScenarioStubContract for testing.
 * Real __invoke sets 'score' from an API call.
 * applyStub sets 'score' from stub data with a custom transformation.
 */
class StubContractAction extends ActionBehavior implements ScenarioStubContract
{
    public static bool $invokeWasCalled    = false;
    public static bool $applyStubWasCalled = false;

    public function __invoke(State $state): void
    {
        self::$invokeWasCalled = true;
        // In real usage, this would call an external API
        $state->context->set('score', 999);
    }

    public function applyStub(State $state, array $data): void
    {
        self::$applyStubWasCalled = true;
        // Custom transformation: multiply the score by 10
        $state->context->set('score', ($data['score'] ?? 0) * 10);
    }

    public static function reset(): void
    {
        self::$invokeWasCalled    = false;
        self::$applyStubWasCalled = false;
    }
}
