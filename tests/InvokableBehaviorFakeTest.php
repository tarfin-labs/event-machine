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

it('can set run expectations with various configurations', function (): void {
    // 1. Arrange
    $context = new ContextManager(['count' => 0]);

    // Test basic expectation
    TestIncrementAction::fake();
    TestIncrementAction::shouldRun();
    TestIncrementAction::run($context);
    TestIncrementAction::assertRan();

    // Reset for next test
    TestIncrementAction::resetFakes();

    // Test with multiple calls and specific configuration
    TestIncrementAction::fake();
    TestIncrementAction::shouldRun()
        ->twice()
        ->withAnyArgs();

    TestIncrementAction::run($context);
    TestIncrementAction::run($context);
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

// region Edge Cases for shouldRun and shouldReturn

it('handles calling shouldReturn without explicit fake call', function (): void {
    $context = new ContextManager(['value' => 5]);

    // shouldReturn implicitly calls fake()
    TestCountGuard::shouldReturn(true);
    expect(TestCountGuard::isFaked())->toBeTrue();
    expect(TestCountGuard::run($context))->toBeTrue();
});

it('handles calling shouldRun without explicit fake call', function (): void {
    $context = new ContextManager(['count' => 0]);

    // shouldRun implicitly calls fake()
    TestIncrementAction::shouldRun()->once();
    expect(TestIncrementAction::isFaked())->toBeTrue();

    TestIncrementAction::run($context);
    TestIncrementAction::assertRan();
});

it('verifies expectation counts accurately', function (): void {
    TestIncrementAction::shouldRun()->times(3);

    $context = new ContextManager();
    TestIncrementAction::run($context);
    TestIncrementAction::run($context);

    // Should fail if we don't call it a third time
    expect(fn () => TestIncrementAction::getFake()->mockery_verify())
        ->toThrow(\Mockery\Exception\InvalidCountException::class);
});

// endregion

// region Guard Behavior Tests

it('can fake guard behavior with various return value patterns', function (): void {
    // 1. Arrange
    $context = new ContextManager(['count' => 0]);

    // Test ordered expectations with different return values
    TestCountGuard::fake();
    TestCountGuard::shouldRun()
        ->once()
        ->andReturn(true)
        ->ordered();

    expect(TestCountGuard::run($context))->toBeTrue();

    TestCountGuard::shouldRun()
        ->once()
        ->andReturn(false)
        ->ordered();

    expect(TestCountGuard::run($context))->toBeFalse();
    TestCountGuard::assertRan();

    // Reset and test consecutive return values
    TestCountGuard::resetFakes();
    TestCountGuard::fake();
    TestCountGuard::shouldRun()
        ->times(3)
        ->andReturn(true, false, true);

    expect(TestCountGuard::run($context))->toBeTrue();
    expect(TestCountGuard::run($context))->toBeFalse();
    expect(TestCountGuard::run($context))->toBeTrue();
});

it('can handle multiple shouldReturn calls in the same test', function (): void {
    // 1. Arrange
    $context = new ContextManager(['value' => 10]);

    // 2. Act & 3. Assert - First call
    TestCountGuard::shouldReturn(true);
    expect(TestCountGuard::run($context))->toBeTrue();

    TestCountGuard::shouldReturn(false);
    expect(TestCountGuard::run($context))->toBeFalse();

    TestCountGuard::shouldReturn(true);
    expect(TestCountGuard::run($context))->toBeTrue();
});

it('can mix shouldRun and shouldReturn calls in the same test', function (): void {
    // 1. Arrange
    $context = new ContextManager(['value' => 5]);

    TestCountGuard::shouldRun()
        ->once()
        ->andReturn(true);
    expect(TestCountGuard::run($context))->toBeTrue();

    TestCountGuard::shouldReturn(false);
    expect(TestCountGuard::run($context))->toBeFalse();

    TestCountGuard::shouldRun()
        ->once()
        ->andReturn(true);
    expect(TestCountGuard::run($context))->toBeTrue();
});

// endregion

// region Complex Return Types and Edge Cases

it('handles multiple consecutive calls with never() expectation', function (): void {
    TestIncrementAction::shouldRun()->never();

    // Should not throw since we're not calling it
    TestIncrementAction::assertNotRan();
});

it('can chain multiple mock configurations', function (): void {
    $context = new ContextManager(['count' => 5]);

    TestIncrementAction::shouldRun()
        ->once()
        ->with(\Mockery::type(ContextManager::class))
        ->andReturnUsing(function (ContextManager $ctx): void {
            $ctx->set('count', $ctx->get('count') * 2);
        });

    TestIncrementAction::run($context);
    expect($context->get('count'))->toBe(10);
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

it('handles multiple shouldRun calls across different behaviors', function (): void {
    // 1. Arrange
    $context = new ContextManager(['count' => 0]);

    TestIncrementAction::shouldRun()
        ->once()
        ->withAnyArgs();

    TestCountGuard::shouldReturn(false);

    // 2. Act
    TestIncrementAction::run($context);
    expect(TestCountGuard::run($context))->toBeFalse();

    // 3. Assert first round
    TestIncrementAction::assertRan();

    // First behavior - second expectation
    TestIncrementAction::shouldRun()
        ->once()
        ->andReturnUsing(function (ContextManager $ctx): void {
            $ctx->set('count', 10);
        });

    // Second behavior - second expectation
    TestCountGuard::shouldReturn(true);

    // 4. Act again
    TestIncrementAction::run($context);
    expect(TestCountGuard::run($context))->toBeTrue();
    expect($context->get('count'))->toBe(10);
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

it('completely resets all fakes and cleans up resources', function (): void {
    // 1. Arrange
    TestIncrementAction::shouldRun()->once();
    TestCountGuard::shouldRun()->twice();

    // 2. Act
    EventMachine::resetAllFakes();

    // 3. Assert - Verify fakes are reset
    expect(TestIncrementAction::isFaked())->toBeFalse()
        ->and(TestCountGuard::isFaked())->toBeFalse()
        ->and(TestIncrementAction::getFake())->toBeNull()
        ->and(TestCountGuard::getFake())->toBeNull();

    // Verify container cleanup
    expect(app()->bound(TestIncrementAction::class))->toBeFalse()
        ->and(app()->bound(TestCountGuard::class))->toBeFalse();

    // Verify mockery cleanup by setting new expectations
    TestIncrementAction::shouldRun()->never();
    TestCountGuard::shouldRun()->never();

    TestIncrementAction::assertNotRan();
    TestCountGuard::assertNotRan();
});

it('maintains behavior isolation and consistency after resetting fakes', function (): void {
    // Test isolation after reset
    TestIncrementAction::shouldRun()->once();
    TestCountGuard::shouldRun()->twice();
    EventMachine::resetAllFakes();

    TestIncrementAction::shouldRun()->once();
    TestIncrementAction::run(new ContextManager(['count' => 0]));

    TestIncrementAction::assertRan();
    expect(TestCountGuard::isFaked())->toBeFalse();

    // Test consistent reset with different trait instances
    TestIncrementAction::shouldRun()->once();
    TestCountGuard::shouldRun()->twice();

    TestIncrementAction::resetAllFakes();

    TestIncrementAction::shouldRun()->once();
    TestCountGuard::shouldRun()->once();

    TestCountGuard::resetAllFakes();

    expect(TestIncrementAction::isFaked())->toBeFalse()
        ->and(TestCountGuard::isFaked())->toBeFalse()
        ->and(TestIncrementAction::getFake())->toBeNull()
        ->and(TestCountGuard::getFake())->toBeNull();
});

// endregion
