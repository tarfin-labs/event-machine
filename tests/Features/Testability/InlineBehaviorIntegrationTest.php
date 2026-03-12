<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Testing\InlineBehaviorFake;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\InlineBehaviorMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\IncrementAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\Actions\IncrementWithServiceAction;

afterEach(function (): void {
    IncrementAction::resetAllFakes();
});

// ═════════════════════════════════════════════════════════════
//  faking() API — Inline Behaviors
// ═════════════════════════════════════════════════════════════

it('fakes an inline action and asserts it ran', function (): void {
    TestMachine::create(InlineBehaviorMachine::class)
        ->faking(['processAction'])
        ->send('PROCESS')
        ->assertState('active')
        ->assertBehaviorRan('processAction');
});

it('fakes an inline action and the original closure does NOT run', function (): void {
    TestMachine::create(InlineBehaviorMachine::class)
        ->faking(['processAction'])
        ->send('PROCESS')
        ->assertState('active')
        ->assertContext('processed', false); // original sets true, fake no-op leaves false
});

it('fakes an inline guard with return value via key-value syntax', function (): void {
    // blockingGuard normally returns false (blocks transition)
    // Override to return true → transition should proceed
    TestMachine::create(InlineBehaviorMachine::class)
        ->faking(['blockingGuard' => true])
        ->send('GUARDED')
        ->assertState('active');
});

it('fakes an inline guard with false to block transition', function (): void {
    // isAllowedGuard normally returns true, override to false
    TestMachine::create(InlineBehaviorMachine::class)
        ->faking(['isAllowedGuard' => false])
        ->assertGuarded('PROCESS');
});

it('fakes an inline behavior with custom replacement closure', function (): void {
    TestMachine::create(InlineBehaviorMachine::class)
        ->faking([
            'processAction' => fn (ContextManager $context) => $context->set('count', 999),
        ])
        ->send('PROCESS')
        ->assertState('active')
        ->assertContext('count', 999);
});

it('mixes class-based and inline behaviors in faking()', function (): void {
    TestMachine::create(InlineBehaviorMachine::class)
        ->faking([
            IncrementWithServiceAction::class, // class-based spy
            'processAction',                    // inline fake
        ])
        ->send('CLASS_ACTION')
        ->assertState('active')
        ->assertBehaviorRan(IncrementWithServiceAction::class);
});

it('class-based FQCNs in faking() still route to ::spy()', function (): void {
    $tm = TestMachine::create(InlineBehaviorMachine::class)
        ->faking([IncrementWithServiceAction::class]);

    expect(IncrementWithServiceAction::isFaked())->toBeTrue();
});

// ═════════════════════════════════════════════════════════════
//  Key Validation
// ═════════════════════════════════════════════════════════════

it('throws on unknown inline behavior key in faking()', function (): void {
    expect(fn () => TestMachine::create(InlineBehaviorMachine::class)
        ->faking(['nonExistentAction'])
    )->toThrow(InvalidArgumentException::class, 'not found in machine definition');
});

it('accepts valid inline behavior keys from the machine definition', function (): void {
    // Should not throw — all keys exist in InlineBehaviorMachine behavior array
    $tm = TestMachine::create(InlineBehaviorMachine::class)
        ->faking(['processAction', 'isAllowedGuard' => true, 'doubleCountCalculator']);

    expect(InlineBehaviorFake::isFaked('processAction'))->toBeTrue();
    expect(InlineBehaviorFake::isFaked('isAllowedGuard'))->toBeTrue();
    expect(InlineBehaviorFake::isFaked('doubleCountCalculator'))->toBeTrue();
});
