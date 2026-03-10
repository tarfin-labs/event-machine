<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Traits;

use Mockery;
use RuntimeException;
use Mockery\MockInterface;
use Illuminate\Support\Facades\App;

trait Fakeable
{
    /** @var array<class-string, MockInterface> Tracks faked classes for assertions and cleanup. */
    private static array $fakes = [];

    /** @var array<class-string, bool> Tracks which fakes are spies (true) vs strict mocks (false). */
    private static array $spies = [];

    // ─── Creation ─────────────────────────────────

    /**
     * Create a Mockery mock and register it in the container.
     *
     * Uses App::bind() with a closure — NOT App::instance() — because
     * instance bindings are silently bypassed when App::make() receives
     * explicit parameters (e.g., ['eventQueue' => $queue]).
     */
    public static function fake(): MockInterface
    {
        if (isset(static::$fakes[static::class])) {
            static::resetFakes();
        }

        $mock                         = Mockery::mock(static::class);
        static::$fakes[static::class] = $mock;
        static::$spies[static::class] = false;
        App::bind(static::class, fn () => $mock);

        return $mock;
    }

    /**
     * Create a Mockery spy (allows all calls, records them).
     */
    public static function spy(): MockInterface
    {
        // If already a spy, return it (idempotent)
        if (isset(static::$fakes[static::class]) && (static::$spies[static::class] ?? false)) {
            return static::$fakes[static::class];
        }

        // If a strict mock exists, teardown before creating spy
        if (isset(static::$fakes[static::class])) {
            static::resetFakes();
        }

        $spy                          = Mockery::spy(static::class);
        static::$fakes[static::class] = $spy;
        static::$spies[static::class] = true;
        App::bind(static::class, fn () => $spy);

        return $spy;
    }

    // ─── Expectations ─────────────────────────────

    /**
     * Expect __invoke to be called. Implicitly fakes.
     */
    public static function shouldRun(): Mockery\Expectation|Mockery\CompositeExpectation
    {
        return static::fake()->shouldReceive('__invoke');
    }

    /**
     * Expect __invoke to NOT be called. Implicitly fakes.
     */
    public static function shouldNotRun(): Mockery\Expectation|Mockery\CompositeExpectation
    {
        return static::fake()->shouldNotReceive('__invoke');
    }

    /**
     * Set return value for __invoke. Implicitly fakes.
     */
    public static function shouldReturn(mixed $value): void
    {
        static::fake()->shouldReceive('__invoke')->andReturn($value);
    }

    /**
     * Spy mode — records calls silently, allows all.
     */
    public static function allowToRun(): Mockery\Expectation|Mockery\CompositeExpectation
    {
        return static::spy()->allows('__invoke');
    }

    // ─── Assertions ───────────────────────────────

    /**
     * Assert __invoke was called at least once.
     */
    public static function assertRan(): void
    {
        if (!isset(static::$fakes[static::class])) {
            throw new RuntimeException('Behavior '.static::class.' was not faked.');
        }

        static::$fakes[static::class]->shouldHaveReceived('__invoke');
    }

    /**
     * Assert __invoke was NOT called.
     */
    public static function assertNotRan(): void
    {
        if (!isset(static::$fakes[static::class])) {
            throw new RuntimeException('Behavior '.static::class.' was not faked.');
        }

        static::$fakes[static::class]->shouldNotHaveReceived('__invoke');
    }

    /**
     * Assert __invoke was called with arguments matching the callback.
     */
    public static function assertRanWith(callable $callback): void
    {
        if (!isset(static::$fakes[static::class])) {
            throw new RuntimeException('Behavior '.static::class.' was not faked.');
        }

        static::$fakes[static::class]
            ->shouldHaveReceived('__invoke')
            ->withArgs($callback);
    }

    /**
     * Assert __invoke was called exactly N times.
     */
    public static function assertRanTimes(int $count): void
    {
        if (!isset(static::$fakes[static::class])) {
            throw new RuntimeException('Behavior '.static::class.' was not faked.');
        }

        static::$fakes[static::class]
            ->shouldHaveReceived('__invoke')
            ->times($count);
    }

    // ─── Inspection ───────────────────────────────

    /**
     * Check if the behavior is currently faked.
     */
    public static function isFaked(): bool
    {
        return isset(static::$fakes[static::class]);
    }

    /**
     * Get the current mock/spy instance, if faked.
     */
    public static function getFake(): ?MockInterface
    {
        return static::$fakes[static::class] ?? null;
    }

    // ─── Cleanup ──────────────────────────────────

    /**
     * Reset fake for this specific behavior.
     *
     * Uses app()->offsetUnset() to remove App::bind() bindings.
     * Note: App::forgetInstance() only removes App::instance() bindings,
     * so it would NOT clean up our App::bind() registrations.
     */
    public static function resetFakes(): void
    {
        if (isset(static::$fakes[static::class])) {
            $mock = static::$fakes[static::class];

            app()->offsetUnset(static::class);

            foreach (array_keys($mock->mockery_getExpectations()) as $method) {
                $mock->mockery_setExpectationsFor(
                    $method,
                    new Mockery\ExpectationDirector($method, $mock),
                );
            }
            $mock->mockery_teardown();

            unset(static::$fakes[static::class], static::$spies[static::class]);
        }
    }

    /**
     * Reset ALL fakes across all behavior classes.
     */
    public static function resetAllFakes(): void
    {
        foreach (static::$fakes as $class => $mock) {
            app()->offsetUnset($class);

            foreach (array_keys($mock->mockery_getExpectations()) as $method) {
                $mock->mockery_setExpectationsFor(
                    $method,
                    new Mockery\ExpectationDirector($method, $mock),
                );
            }
            $mock->mockery_teardown();
        }

        static::$fakes = [];
        static::$spies = [];
    }
}
