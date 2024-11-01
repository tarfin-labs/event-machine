<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Traits;

use Mockery;
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

        App::bind(static::class, fn () => $mock);

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
}
