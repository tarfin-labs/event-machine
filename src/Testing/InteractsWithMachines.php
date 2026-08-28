<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Testing;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Scenarios\ScenarioPlayer;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;
use Tarfinlabs\EventMachine\Scenarios\ScenarioDiscovery;
use Tarfinlabs\EventMachine\Analysis\PathCoverageTracker;

/**
 * Auto-resets all EventMachine test state around each test.
 *
 * Uses Laravel's setUp{TraitName} / tearDown{TraitName} convention — no manual
 * resetMachineFakes() or afterEach needed.
 *
 * Requires TestCase extending Laravel's or Testbench's TestCase.
 */
trait InteractsWithMachines
{
    /**
     * Drops half-walked coverage paths, but NOT the observed ones: the tracker
     * accumulates observations across the whole run and exports them on shutdown,
     * while a path left half-finished by a test that stopped at an intermediate
     * state would otherwise be flushed into the next test's signature.
     *
     * This runs in setUp, not teardown, on purpose. Trait teardowns fire in an order
     * the trait itself does not control — Testbench registers them LIFO, Laravel
     * FIFO — so discarding at teardown can land *before* another trait's teardown
     * calls completePath(), and that path is then silently thrown away. Clearing at
     * the start of the next test leaves every teardown free to finish its own paths.
     */
    protected function setUpInteractsWithMachines(): void
    {
        PathCoverageTracker::discardActivePaths();
    }

    protected function tearDownInteractsWithMachines(): void
    {
        Machine::resetMachineFakes();
        Machine::$heldLockIds = [];
        CommunicationRecorder::reset();
        InlineBehaviorFake::resetAll();
        InvokableBehavior::resetAllFakes();
        ScenarioPlayer::cleanupOverrides();
        ScenarioDiscovery::resetCache();
    }
}
