<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Traits;

use Mockery;
use RuntimeException;
use Mockery\MockInterface;
use Illuminate\Support\Facades\App;

trait Fakeable
{
    /** @var array Stores fake instances */
    private static array $fakes = [];

    /**
     * Create a fake instance of the behavior.
     */
    public static function fake(): MockInterface
    {
        $mock = Mockery::mock(static::class);

        static::$fakes[static::class] = $mock;

        App::bind(abstract: static::class, concrete: fn () => $mock);

        return $mock;
    }

    /**
     * Check if the behavior is faked.
     */
    public static function isFaked(): bool
    {
        return isset(static::$fakes[static::class]);
    }

    /**
     * Get the fake instance if exists.
     */
    public static function getFake(): ?MockInterface
    {
        return static::$fakes[static::class] ?? null;
    }

    /**
     * Remove the fake instance from Laravel's container.
     *
     * This method handles the cleanup of fake instances from Laravel's service container
     * to prevent memory leaks and ensure proper state reset between tests.
     */
    protected static function cleanupLaravelContainer(string $class): void
    {
        if (App::has($class)) {
            App::forgetInstance($class);
            App::offsetUnset($class);
        }
    }

    /**
     * Clean up Mockery expectations for a given mock instance.
     *
     * This method resets all expectations on a mock object by:
     * 1. Getting all methods that have expectations
     * 2. Creating new empty expectation directors for each method
     * 3. Performing mockery teardown
     *
     * @param  MockInterface  $mock  The mock instance to clean up
     */
    protected static function cleanupMockeryExpectations(MockInterface $mock): void
    {
        foreach (array_keys($mock->mockery_getExpectations()) as $method) {
            $mock->mockery_setExpectationsFor(
                $method,
                new Mockery\ExpectationDirector($method, $mock),
            );
        }

        $mock->mockery_teardown();
    }

    /**
     * Reset all fakes.
     */
    public static function resetFakes(): void
    {
        static::$fakes = [];
        if (App::has(id: static::class)) {
            App::forgetInstance(abstract: static::class);
            App::offsetUnset(key: static::class);
        }
    }

    /**
     * Reset all fakes in application container.
     */
    public static function resetAllFakes(): void
    {
        foreach (array_keys(static::$fakes) as $class) {
            if (App::has($class)) {
                App::forgetInstance($class);
                App::offsetUnset($class);
            }
        }

        Mockery::resetContainer();
        static::$fakes = [];
    }

    /**
     * Set run expectations for the fake behavior.
     */
    public static function shouldRun(): Mockery\Expectation|Mockery\CompositeExpectation
    {
        if (!isset(static::$fakes[static::class])) {
            static::fake();
        }

        return static::$fakes[static::class]->shouldReceive('__invoke');
    }

    /**
     * Set return value for the fake behavior.
     */
    public static function shouldReturn(mixed $value): void
    {
        if (!isset(static::$fakes[static::class])) {
            static::fake();
        }

        static::$fakes[static::class]->shouldReceive('__invoke')->andReturn($value);
    }

    /**
     * Assert that the behavior was run.
     */
    public static function assertRan(): void
    {
        if (!isset(static::$fakes[static::class])) {
            throw new RuntimeException(message: 'Behavior '.static::class.' was not faked.');
        }

        static::$fakes[static::class]->shouldHaveReceived('__invoke');
    }

    /**
     * Assert that the behavior was not run.
     */
    public static function assertNotRan(): void
    {
        if (!isset(static::$fakes[static::class])) {
            throw new RuntimeException(message: 'Behavior '.static::class.' was not faked.');
        }

        static::$fakes[static::class]->shouldNotHaveReceived('__invoke');
    }
}
