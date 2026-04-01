<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Events\ApproveEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Guards\IsEligibleGuard;

/**
 * Same guard (IsEligibleGuard) appears in two plan states with different values.
 * Used to test detectStateAwareOverrides — last-wins policy.
 *
 * routing: IsEligibleGuard => true (first occurrence)
 * reviewing: IsEligibleGuard => false (second occurrence — this wins)
 *
 * Note: This scenario is structurally valid but would fail execution because
 * the last-wins value (false) means the guard blocks at routing.
 * Used only for unit testing detectStateAwareOverrides and registerOverrides.
 */
class StateAwareOverrideScenario extends MachineScenario
{
    protected string $machine     = ScenarioTestMachine::class;
    protected string $source      = 'idle';
    protected string $event       = MachineScenario::START;
    protected string $target      = 'approved';
    protected string $description = 'State-aware override test — same guard different values';

    protected function plan(): array
    {
        return [
            'routing' => [
                IsEligibleGuard::class => true,
            ],
            'processing' => '@done',
            'reviewing'  => [
                IsEligibleGuard::class => false,
                '@continue'            => ApproveEvent::class,
            ],
        ];
    }
}
