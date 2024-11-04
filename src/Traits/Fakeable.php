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
     * Reset all fakes.
     */
    public static function resetFakes(): void
    {
        static::$fakes = [];
        if (App::has(id: static::class)) {
            App::forgetInstance(abstract: static::class);
        }
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
