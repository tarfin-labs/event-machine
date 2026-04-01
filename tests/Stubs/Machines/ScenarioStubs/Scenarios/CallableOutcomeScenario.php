<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\CallableOutcomeMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Guards\IsRetryableGuard;

/**
 * Callable outcome test scenario.
 *
 * The Closure checks context->pin against expected value.
 * Correct PIN → @done, wrong PIN → @fail with IsRetryableGuard=true (retry to waiting).
 */
class CallableOutcomeScenario extends MachineScenario
{
    protected string $machine     = CallableOutcomeMachine::class;
    protected string $source      = 'idle';
    protected string $event       = MachineScenario::START;
    protected string $target      = 'waiting';
    protected string $description = 'Callable outcome — PIN check';

    protected function plan(): array
    {
        return [];
    }

    protected function continuation(): array
    {
        return [
            'confirming' => [
                'outcome' => function (ContextManager $context): string {
                    $pin         = $context->pin ?? '';
                    $expectedPin = now()->format('dmy');

                    return $pin === $expectedPin ? '@done' : '@fail';
                },
                IsRetryableGuard::class => true,
            ],
        ];
    }
}
