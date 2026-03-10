<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\Actions\LogExitAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\Actions\LogEntryAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\AllInvocationPointsMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\Guards\IsCountPositiveGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\Calculators\DoubleCountCalculator;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\Actions\IncrementWithServiceAction;

afterEach(function (): void {
    IncrementWithServiceAction::resetAllFakes();
});

// ─── Guard faking during Machine::send() ─────────────────────

it('respects faked guard during send', function (): void {
    // Guard returns false → transition should NOT happen
    IsCountPositiveGuard::shouldReturn(false);

    $machine = AllInvocationPointsMachine::create();
    $machine->send(['type' => 'PROCESS']);

    // Machine should stay in idle since guard returned false
    expect($machine->state->matches('idle'))->toBeTrue();

    IsCountPositiveGuard::resetFakes();
});

it('allows transition when faked guard returns true', function (): void {
    IsCountPositiveGuard::shouldReturn(true);

    $machine = AllInvocationPointsMachine::create();
    $machine->send(['type' => 'PROCESS']);

    // Machine should transition to active
    expect($machine->state->matches('active'))->toBeTrue();

    IsCountPositiveGuard::resetFakes();
});

// ─── Transition action faking during Machine::send() ─────────

it('respects faked transition action during send', function (): void {
    IncrementWithServiceAction::shouldRun()->withAnyArgs()->once();

    $machine = AllInvocationPointsMachine::create();
    $machine->send(['type' => 'PROCESS']);

    IncrementWithServiceAction::assertRan();
});

// ─── Entry action faking during Machine::send() ──────────────

it('respects faked entry action during send', function (): void {
    LogEntryAction::allowToRun();

    $machine = AllInvocationPointsMachine::create();
    $machine->send(['type' => 'PROCESS']);

    LogEntryAction::assertRan();

    LogEntryAction::resetFakes();
});

// ─── Exit action faking during Machine::send() ───────────────

it('respects faked exit action during send', function (): void {
    LogExitAction::allowToRun();

    $machine = AllInvocationPointsMachine::create();
    $machine->send(['type' => 'PROCESS']);

    LogExitAction::assertRan();

    LogExitAction::resetFakes();
});

// ─── Calculator faking during Machine::send() ────────────────

it('respects faked calculator during send', function (): void {
    DoubleCountCalculator::allowToRun();

    $machine = AllInvocationPointsMachine::create();
    $machine->send(['type' => 'PROCESS']);

    DoubleCountCalculator::assertRan();

    DoubleCountCalculator::resetFakes();
});

// ─── All invocation points faked together ────────────────────

it('fakes all 5 invocation points simultaneously', function (): void {
    IsCountPositiveGuard::shouldReturn(true);
    DoubleCountCalculator::allowToRun();
    IncrementWithServiceAction::allowToRun();
    LogExitAction::allowToRun();
    LogEntryAction::allowToRun();

    $machine = AllInvocationPointsMachine::create();
    $machine->send(['type' => 'PROCESS']);

    // All fakes were invoked
    DoubleCountCalculator::assertRan();
    IncrementWithServiceAction::assertRan();
    LogExitAction::assertRan();
    LogEntryAction::assertRan();

    // Context should be untouched because all behaviors are faked
    expect($machine->state->context->get('count'))->toBe(1);
    expect($machine->state->context->get('entered'))->toBeFalse();
    expect($machine->state->context->get('exited'))->toBeFalse();

    IsCountPositiveGuard::resetFakes();
    DoubleCountCalculator::resetFakes();
    LogExitAction::resetFakes();
    LogEntryAction::resetFakes();
});
