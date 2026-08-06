<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Definition;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\BehaviorCoverage\MapAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\BehaviorCoverage\ExitAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\BehaviorCoverage\EntryAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\BehaviorCoverage\TimerAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\BehaviorCoverage\TupleAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\BehaviorCoverage\AlwaysAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\BehaviorCoverage\ListenAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\BehaviorCoverage\CoverageGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\BehaviorCoverage\CoverageOutput;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\BehaviorCoverage\TransitionAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\BehaviorCoverage\CoverageCalculator;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\BehaviorCoverage\CoverageStartedEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\BehaviorCoverage\BehaviorCoverageMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioForwardChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioForwardParentMachine;

it('collects a behavior from every route it can be referenced by', function (): void {
    $found = BehaviorCoverageMachine::definition()->referencedBehaviors();

    // Set equality, not containment: an extra class is as much a defect as a missing
    // one, because it means something not checkable slipped into the set.
    expect($found['actions'])->toEqualCanonicalizing([
        AlwaysAction::class,      // an @always branch
        EntryAction::class,       // a state entry key
        ExitAction::class,        // a state exit key
        ListenAction::class,      // the listen config
        MapAction::class,         // the inline behavior map
        TimerAction::class,       // an after/timer transition
        TransitionAction::class,  // a bare FQCN in a transition
        TupleAction::class,       // a behavior tuple
    ])
        ->and($found['guards'])->toEqual([CoverageGuard::class])
        ->and($found['calculators'])->toEqual([CoverageCalculator::class])
        ->and($found['outputs'])->toEqual([CoverageOutput::class]);
});

it('excludes closures and inline keys that resolve to closures', function (): void {
    $found = BehaviorCoverageMachine::definition()->referencedBehaviors();

    // 'inlineAction' resolves to a closure in the behavior map; it has no statically
    // known signature, so nothing about it is checkable and it must not be collected.
    foreach ($found['actions'] as $class) {
        expect(class_exists($class))->toBeTrue();
    }

    expect($found['actions'])->not->toContain('inlineAction');
});

it('returns a deduplicated set in a stable order', function (): void {
    $first  = BehaviorCoverageMachine::definition()->referencedBehaviors();
    $second = BehaviorCoverageMachine::definition()->referencedBehaviors();

    expect($first)->toEqual($second);

    foreach ($first as $classes) {
        expect($classes)->toEqual(array_values(array_unique($classes)));
    }
});

it('keys the set by behavior type', function (): void {
    expect(BehaviorCoverageMachine::definition()->referencedBehaviors())
        ->toHaveKeys(['actions', 'guards', 'calculators', 'outputs'])
        ->and(BehaviorCoverageMachine::definition()->referencedBehaviors())
        ->not->toHaveKey('events');
});

it('collects event classes separately from behaviors', function (): void {
    $definition = BehaviorCoverageMachine::definition();

    expect($definition->referencedEventClasses())->toContain(CoverageStartedEvent::class)
        ->and($definition->referencedBehaviors()['actions'])->not->toContain(CoverageStartedEvent::class);
});

it('stops at the delegation boundary', function (): void {
    $found = ScenarioForwardParentMachine::definition()->referencedBehaviors();
    $all   = array_merge(...array_values($found));

    // Unconditional: the parent delegates to a child machine, and neither the child
    // class nor anything running under the child's context belongs in the parent's
    // set. Asserting on the flattened set means this holds even when it is empty.
    expect($all)->not->toContain(ScenarioForwardChildMachine::class);

    foreach ($all as $class) {
        expect(is_subclass_of($class, InvokableBehavior::class))->toBeTrue();
    }
});
