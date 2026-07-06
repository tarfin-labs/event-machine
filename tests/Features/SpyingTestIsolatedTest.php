<?php

declare(strict_types=1);

use Mockery\Exception\InvalidCountException;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\ProbeOneAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\ProbeTwoAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\BootProbeAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\SpyingProbeMachine;

// ─── spying() ───────────────────────────────────────────

it('spies multiple behaviors in one call', function (): void {
    SpyingProbeMachine::test()
        ->spying([ProbeOneAction::class, ProbeTwoAction::class])
        ->send('GO')
        ->assertBehaviorRan([ProbeOneAction::class, ProbeTwoAction::class]);
});

it('rejects an empty spying list', function (): void {
    expect(fn () => SpyingProbeMachine::test()->spying([]))
        ->toThrow(InvalidArgumentException::class, 'spies nothing');
});

it('rejects non-InvokableBehavior class entries', function (): void {
    expect(fn () => SpyingProbeMachine::test()->spying([stdClass::class]))
        ->toThrow(InvalidArgumentException::class, 'InvokableBehavior subclass FQCNs');
});

it('rejects non-behavior entries with an inline hint', function (): void {
    expect(fn () => SpyingProbeMachine::test()->spying(['inlineProbeAction']))
        ->toThrow(InvalidArgumentException::class, "InlineBehaviorFake::spy('key')");
});

it('does not observe boot-time entry actions via spying', function (): void {
    // BootProbeAction runs during machine boot — before spying() applies.
    SpyingProbeMachine::test()->spying([BootProbeAction::class]);

    BootProbeAction::assertNotRan();
});

it('observes boot-time entry actions via the pre-init faking parameter', function (): void {
    SpyingProbeMachine::test(faking: [BootProbeAction::class]);

    BootProbeAction::assertRan();
});

// ─── testIsolated() ─────────────────────────────────────

it('testIsolated equals test plus fakingAllActions', function (): void {
    $isolated = SpyingProbeMachine::testIsolated();
    $isolated->send('GO')->assertState('finished');
    ProbeOneAction::assertRan();
    ProbeTwoAction::assertRan();

    ProbeOneAction::resetAllFakes();

    $longForm = SpyingProbeMachine::test()->fakingAllActions();
    $longForm->send('GO')->assertState('finished');
    ProbeOneAction::assertRan();
    ProbeTwoAction::assertRan();
});

it('passes the faking parameter through to boot time', function (): void {
    SpyingProbeMachine::testIsolated(faking: [BootProbeAction::class]);

    BootProbeAction::assertRan();
});

it('throws when fakingAllActions except is applied after testIsolated', function (): void {
    expect(fn () => SpyingProbeMachine::testIsolated()->fakingAllActions(except: [ProbeOneAction::class]))
        ->toThrow(LogicException::class, 'cannot be applied after all actions were already faked');
});

it('allows a redundant fakingAllActions without except after testIsolated', function (): void {
    SpyingProbeMachine::testIsolated()->fakingAllActions()->send('GO')->assertState('finished');
});

// ─── assertBehaviorRan(array) ───────────────────────────

it('asserts mixed FQCN and inline entries ran', function (): void {
    SpyingProbeMachine::test()
        ->faking(['inlineProbeAction'])
        ->spying([ProbeOneAction::class])
        ->send('GO')
        ->assertBehaviorRan([ProbeOneAction::class, 'inlineProbeAction']);
});

it('names the entry that did not run', function (): void {
    $machine = SpyingProbeMachine::test()->spying([ProbeOneAction::class, ProbeTwoAction::class]);

    // GO never sent — Mockery reports the unmet spy expectation, naming the class
    expect(fn () => $machine->assertBehaviorRan([ProbeOneAction::class]))
        ->toThrow(InvalidCountException::class, 'ProbeOneAction');
});

it('rejects an empty assertBehaviorRan list', function (): void {
    expect(fn () => SpyingProbeMachine::test()->assertBehaviorRan([]))
        ->toThrow(InvalidArgumentException::class, 'asserts nothing');
});
