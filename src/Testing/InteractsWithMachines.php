<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Testing;

use Tarfinlabs\EventMachine\Actor\Machine;

/**
 * Auto-resets all EventMachine test state after each test.
 *
 * Uses Laravel's tearDown{TraitName} convention — no manual
 * resetMachineFakes() or afterEach needed.
 *
 * Requires TestCase extending Laravel's or Testbench's TestCase.
 */
trait InteractsWithMachines
{
    protected function tearDownInteractsWithMachines(): void
    {
        Machine::resetMachineFakes();
        CommunicationRecorder::reset();
        InlineBehaviorFake::resetAll();
    }
}
