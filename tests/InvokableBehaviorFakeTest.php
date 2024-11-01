<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests;

use RuntimeException;
use Mockery\MockInterface;
use Tarfinlabs\EventMachine\ContextManager;
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
