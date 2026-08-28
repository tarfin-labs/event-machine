<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Testing;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Scenarios\ScenarioPlayer;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;
use Tarfinlabs\EventMachine\Scenarios\ScenarioDiscovery;
use Tarfinlabs\EventMachine\Analysis\PathCoverageTracker;

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
        Machine::$heldLockIds = [];
        CommunicationRecorder::reset();
        InlineBehaviorFake::resetAll();
        InvokableBehavior::resetAllFakes();
        ScenarioPlayer::cleanupOverrides();
        ScenarioDiscovery::resetCache();

        // Half-walked coverage paths, but NOT the observed ones: the tracker accumulates
        // observations across the whole run and exports them on shutdown, while a path
        // left half-finished by a test that stopped at an intermediate state would
        // otherwise be flushed into the next test's signature.
        PathCoverageTracker::discardActivePaths();
    }
}
