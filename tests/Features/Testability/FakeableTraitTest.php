<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Guards\IsEvenGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\IncrementAction;

afterEach(function (): void {
    IncrementAction::resetAllFakes();
});

// ─── fake() ──────────────────────────────────────────────────

it('creates a mock and binds it in the container via fake()', function (): void {
    $mock = IncrementAction::fake();

    expect(IncrementAction::isFaked())->toBeTrue();
    expect(IncrementAction::getFake())->toBe($mock);

    // Container resolves the mock
    $resolved = App::make(IncrementAction::class);
    expect($resolved)->toBe($mock);
});

it('tears down previous mock when fake() is called again', function (): void {
    $mock1 = IncrementAction::fake();
    $mock2 = IncrementAction::fake();

    // fake() always creates a fresh mock
    expect($mock1)->not->toBe($mock2);
    expect(IncrementAction::getFake())->toBe($mock2);

    // Container resolves the second mock, not the leaked first one
    $resolved = App::make(IncrementAction::class);
    expect($resolved)->toBe($mock2);
});

// ─── spy() ───────────────────────────────────────────────────

it('creates a spy and binds it in the container via spy()', function (): void {
    $spy = IncrementAction::spy();

    expect(IncrementAction::isFaked())->toBeTrue();
    expect(IncrementAction::getFake())->toBe($spy);

    $resolved = App::make(IncrementAction::class);
    expect($resolved)->toBe($spy);
});

it('returns existing fake when spy() is called after fake()', function (): void {
    $mock = IncrementAction::fake();
    $spy  = IncrementAction::spy();

    expect($mock)->toBe($spy);
});

// ─── shouldRun() ─────────────────────────────────────────────

it('sets expectation for __invoke via shouldRun()', function (): void {
    IncrementAction::shouldRun()->withAnyArgs()->once();

    $ctx  = Mockery::mock(TrafficLightsContext::class);
    $mock = App::make(IncrementAction::class);
    $mock->__invoke($ctx);
});

// ─── shouldNotRun() ──────────────────────────────────────────

it('sets never-called expectation via shouldNotRun()', function (): void {
    IncrementAction::shouldNotRun();

    // Not calling __invoke — Mockery verifies at teardown
    expect(IncrementAction::isFaked())->toBeTrue();
});

// ─── shouldReturn() ──────────────────────────────────────────

it('sets return value for __invoke via shouldReturn()', function (): void {
    IsEvenGuard::shouldReturn(true);

    $ctx  = Mockery::mock(TrafficLightsContext::class);
    $mock = App::make(IsEvenGuard::class);
    expect($mock->__invoke($ctx))->toBeTrue();

    IsEvenGuard::resetFakes();
});

// ─── allowToRun() ────────────────────────────────────────────

it('allows __invoke calls via spy with allowToRun()', function (): void {
    IncrementAction::allowToRun();

    $ctx = Mockery::mock(TrafficLightsContext::class);
    $spy = App::make(IncrementAction::class);
    $spy->__invoke($ctx);

    IncrementAction::assertRan();
});

// ─── assertRan() ─────────────────────────────────────────────

it('asserts __invoke was called at least once', function (): void {
    IncrementAction::allowToRun();

    $ctx = Mockery::mock(TrafficLightsContext::class);
    $spy = App::make(IncrementAction::class);
    $spy->__invoke($ctx);

    IncrementAction::assertRan();
});

it('throws when assertRan() is called on unfaked behavior', function (): void {
    IncrementAction::resetAllFakes();

    IncrementAction::assertRan();
})->throws(RuntimeException::class, 'was not faked');

// ─── assertNotRan() ──────────────────────────────────────────

it('asserts __invoke was NOT called', function (): void {
    IncrementAction::allowToRun();

    // Never call __invoke
    IncrementAction::assertNotRan();
});

it('throws when assertNotRan() is called on unfaked behavior', function (): void {
    IncrementAction::resetAllFakes();

    IncrementAction::assertNotRan();
})->throws(RuntimeException::class, 'was not faked');

// ─── assertRanWith() ─────────────────────────────────────────

it('asserts __invoke was called with matching arguments', function (): void {
    IncrementAction::allowToRun();

    $ctx = Mockery::mock(TrafficLightsContext::class);
    $spy = App::make(IncrementAction::class);
    $spy->__invoke($ctx);

    IncrementAction::assertRanWith(fn ($arg) => $arg instanceof TrafficLightsContext);
});

it('throws when assertRanWith() is called on unfaked behavior', function (): void {
    IncrementAction::resetAllFakes();

    IncrementAction::assertRanWith(fn () => true);
})->throws(RuntimeException::class, 'was not faked');

// ─── assertRanTimes() ────────────────────────────────────────

it('asserts __invoke was called exactly N times', function (): void {
    IncrementAction::allowToRun();

    $ctx = Mockery::mock(TrafficLightsContext::class);
    $spy = App::make(IncrementAction::class);
    $spy->__invoke($ctx);
    $spy->__invoke($ctx);
    $spy->__invoke($ctx);

    IncrementAction::assertRanTimes(3);
});

it('throws when assertRanTimes() is called on unfaked behavior', function (): void {
    IncrementAction::resetAllFakes();

    IncrementAction::assertRanTimes(1);
})->throws(RuntimeException::class, 'was not faked');

// ─── isFaked() / getFake() ───────────────────────────────────

it('returns false from isFaked() when not faked', function (): void {
    expect(IncrementAction::isFaked())->toBeFalse();
});

it('returns null from getFake() when not faked', function (): void {
    expect(IncrementAction::getFake())->toBeNull();
});

// ─── resetFakes() ────────────────────────────────────────────

it('resets a specific behavior fake', function (): void {
    IncrementAction::fake();
    expect(IncrementAction::isFaked())->toBeTrue();

    IncrementAction::resetFakes();

    expect(IncrementAction::isFaked())->toBeFalse();
    expect(IncrementAction::getFake())->toBeNull();
});

it('resolves fresh instance from container after resetFakes()', function (): void {
    IncrementAction::fake();
    IncrementAction::resetFakes();

    $resolved = App::make(IncrementAction::class);
    expect($resolved)->toBeInstanceOf(IncrementAction::class);
    expect($resolved)->not->toBeInstanceOf(Mockery\MockInterface::class);
});

// ─── resetAllFakes() ─────────────────────────────────────────

it('resets all behavior fakes across different classes', function (): void {
    IncrementAction::fake();
    IsEvenGuard::fake();

    expect(IncrementAction::isFaked())->toBeTrue();
    expect(IsEvenGuard::isFaked())->toBeTrue();

    IncrementAction::resetAllFakes();

    expect(IncrementAction::isFaked())->toBeFalse();
    expect(IsEvenGuard::isFaked())->toBeFalse();
});

it('resets all fakes when called from any behavior class', function (): void {
    IncrementAction::fake();
    IsEvenGuard::fake();

    // Calling resetAllFakes() from IsEvenGuard clears both classes
    IsEvenGuard::resetAllFakes();

    expect(IncrementAction::isFaked())->toBeFalse();
    expect(IsEvenGuard::isFaked())->toBeFalse();

    // Container resolves fresh instances, not mocks
    expect(App::make(IncrementAction::class))->not->toBeInstanceOf(Mockery\MockInterface::class);
    expect(App::make(IsEvenGuard::class))->not->toBeInstanceOf(Mockery\MockInterface::class);
});

// ─── Different behavior classes maintain separate fakes ──────

it('maintains separate fakes for different behavior classes', function (): void {
    $actionMock = IncrementAction::fake();
    $guardMock  = IsEvenGuard::fake();

    expect(IncrementAction::getFake())->toBe($actionMock);
    expect(IsEvenGuard::getFake())->toBe($guardMock);
    expect($actionMock)->not->toBe($guardMock);

    IsEvenGuard::resetFakes();
});
