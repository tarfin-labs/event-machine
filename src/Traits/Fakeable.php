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
}
