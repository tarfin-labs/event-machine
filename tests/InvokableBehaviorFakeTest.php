<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests;

use RuntimeException;
use Mockery\MockInterface;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Facades\EventMachine;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class TestIncrementAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('count', ($context->get('count') ?? 0) + 1);
    }
}

class TestCountGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        return $context->get('count') > 0;
    }
}

beforeEach(function (): void {
    $this->testAction = new TestIncrementAction();
    $this->testGuard  = new TestCountGuard();
});

afterEach(function (): void {
    TestIncrementAction::resetFakes();
    TestCountGuard::resetFakes();
});

// region Basic Fake Operations

it('can create a fake instance', function (): void {
    // 1. Act
    TestIncrementAction::fake();
    TestCountGuard::fake();

    // 2. Assert
    expect(TestIncrementAction::isFaked())->toBe(true);
    expect(TestIncrementAction::getFake())->toBeInstanceOf(MockInterface::class);

    expect(TestCountGuard::isFaked())->toBe(true);
    expect(TestCountGuard::getFake())->toBeInstanceOf(MockInterface::class);
});

it('returns null for non-faked behavior', function (): void {
    expect(TestIncrementAction::getFake())->toBeNull();
    expect(TestIncrementAction::isFaked())->toBeFalse();
});

it('provides a cleaner syntax with `shouldReturn()` for simple return value mocking', function (): void {
    // 1. Arrange
    $context = new ContextManager(['count' => -1]);

    // 2. Act
    TestCountGuard::shouldReturn(true);

    // 3. Assert
    expect(TestCountGuard::run($context))->toBeTrue();
});

it('properly resets fakes', function (): void {
    // 1. Arrange
    TestIncrementAction::fake();
    TestCountGuard::fake();

    expect(TestIncrementAction::isFaked())->toBeTrue();
    expect(TestCountGuard::isFaked())->toBeTrue();

    // 2. Act
    TestIncrementAction::resetFakes();
    TestCountGuard::resetFakes();

    // 3. Assert
    expect(TestIncrementAction::isFaked())->toBeFalse();
    expect(TestCountGuard::isFaked())->toBeFalse();
    expect(TestIncrementAction::getFake())->toBeNull();
    expect(TestCountGuard::getFake())->toBeNull();
});

// endregion

// region Action Behaviors

it('can set run expectations', function (): void {
    // 1. Arrange
    $context = new ContextManager(['count' => 0]);

    TestIncrementAction::fake();
    TestIncrementAction::shouldRun();

    // 2. Act
    TestIncrementAction::run($context);

    // 3. Assert
    TestIncrementAction::assertRan();
});

it('works with multiple calls', function (): void {
    // 1. Arrange
    $context = new ContextManager(['count' => 0]);

    TestIncrementAction::fake();
    TestIncrementAction::shouldRun()
        ->twice()
        ->withAnyArgs();

    // 2. Act
    TestIncrementAction::run($context);
    TestIncrementAction::run($context);

    // 3. Assert
    TestIncrementAction::assertRan();
});

it('can mock action to modify context', function (): void {
    // 1. Arrange
    $context = new ContextManager(['count' => 0]);

    TestIncrementAction::fake();
    TestIncrementAction::shouldRun()
        ->once()
        ->andReturnUsing(function (ContextManager $ctx): void {
            $ctx->set('count', 42);
            $ctx->set('modified', true);
        });

    // 2. Act
    TestIncrementAction::run($context);

    // 3. Assert
    expect($context->get('count'))->toBe(42)
        ->and($context->get('modified'))->toBeTrue();
});

it('returns real instance result when not faked', function (): void {
    // 1. Arrange
    $context = new ContextManager(['count' => 0]);

    // 2. Act
    TestIncrementAction::run($context);

    // 3. Assert
    expect($context->get('count'))->toBe(1);
    expect(TestCountGuard::run($context))->toBeTrue(); // Increased by the TestIncrementAction
});

it('can verify method arguments', function (): void {
    // 1. Arrange
    $context = new ContextManager(['count' => 5]);

    TestIncrementAction::fake();
    TestIncrementAction::shouldRun()
        ->once()
        ->withArgs(function (ContextManager $receivedContext) use ($context) {
            return $receivedContext->get('count') === $context->get('count');
        });

    // 2. Act
    TestIncrementAction::run($context);

    // 3. Assert
    TestIncrementAction::assertRan();
});

it('can verify behavior was not run', function (): void {
    // 1. Arrange
    TestIncrementAction::fake();

    // 2. Act - intentionally not calling the action

    // 3. Assert
    TestIncrementAction::assertNotRan();
});

it('can use mock to throw exceptions', function (): void {
    // 1. Arrange
    $context = new ContextManager(['count' => 0]);
    TestIncrementAction::fake();
    TestIncrementAction::shouldRun()
        ->once()
        ->andThrow(new RuntimeException('Test exception'));

    // 2. Act & 3. Assert
    expect(fn () => TestIncrementAction::run($context))
        ->toThrow(RuntimeException::class, 'Test exception');
});

// endregion

// region Guard Behavior Tests

it('can fake guard behavior with different return values', function (): void {
    // 1. Arrange
    $context = new ContextManager(['count' => 0]);

    TestCountGuard::fake();
    TestCountGuard::shouldRun()
        ->once()
        ->andReturn(true)
        ->ordered();

    TestCountGuard::shouldRun()
        ->once()
        ->andReturn(false)
        ->ordered();

    // 2. Act & 3. Assert
    expect(TestCountGuard::run($context))->toBeTrue();
    expect(TestCountGuard::run($context))->toBeFalse();
    TestCountGuard::assertRan();
});

it('can handle consecutive different return values', function (): void {
    // 1. Arrange
    $context = new ContextManager(['count' => 0]);

    TestCountGuard::fake();
    TestCountGuard::shouldRun()
        ->times(3)
        ->andReturn(true, false, true);

    // 2. Act & 3. Assert
    expect(TestCountGuard::run($context))->toBeTrue();
    expect(TestCountGuard::run($context))->toBeFalse();
    expect(TestCountGuard::run($context))->toBeTrue();
});

// endregion

// region Behavior Isolation Tests

it('maintains separate fake states for different behaviors', function (): void {
    // 1. Arrange
    TestIncrementAction::fake();
    TestCountGuard::fake();

    TestIncrementAction::shouldRun()->never();
    TestCountGuard::shouldRun()->once()->andReturn(true);

    // 2. Act
    TestCountGuard::run(new ContextManager());

    // 3. Assert
    TestCountGuard::assertRan();
    TestIncrementAction::assertNotRan();
});

// endregion

// region Exception Handling Tests

it('throws exception when asserting non-faked behavior', function (): void {
    $expectedMessage = 'Behavior '.TestIncrementAction::class.' was not faked.';

    expect(fn () => TestIncrementAction::assertRan())
        ->toThrow(RuntimeException::class, $expectedMessage);
});

it('throws exception when asserting non-faked behavior was not run', function (): void {
    $expectedMessage = 'Behavior '.TestIncrementAction::class.' was not faked.';

    expect(fn () => TestIncrementAction::assertNotRan())
        ->toThrow(RuntimeException::class, $expectedMessage);
});

// endregion

// region Reset All Fakes

it('can reset all fakes at once', function (): void {
    // 1. Arrange
    TestIncrementAction::shouldRun()->once();
    TestCountGuard::shouldRun()->twice();

    // 2. Act
    EventMachine::resetAllFakes();

    // 3. Assert
    expect(TestIncrementAction::isFaked())->toBeFalse()
        ->and(TestCountGuard::isFaked())->toBeFalse()
        ->and(TestIncrementAction::getFake())->toBeNull()
        ->and(TestCountGuard::getFake())->toBeNull();
});

it('removes all fake instances from container when resetting', function (): void {
    // 1. Arrange
    TestIncrementAction::shouldRun()->once();
    TestCountGuard::shouldRun()->once();

    // 2. Act
    EventMachine::resetAllFakes();

    // 3. Assert
    expect(app()->bound(TestIncrementAction::class))->toBeFalse()
        ->and(app()->bound(TestCountGuard::class))->toBeFalse();
});

it('cleans mockery container when resetting fakes', function (): void {
    // 1. Arrange
    TestIncrementAction::shouldRun()->once();
    TestCountGuard::shouldRun()->twice();

    // 2. Act
    EventMachine::resetAllFakes();

    // 3. Assert
    TestIncrementAction::shouldRun()->never();
    TestIncrementAction::assertNotRan();

    TestCountGuard::shouldRun()->never();
    TestCountGuard::assertNotRan();
});
// endregion
