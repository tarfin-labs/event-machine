<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Traits;

use Tarfinlabs\EventMachine\Enums\BehaviorType;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Exceptions\BehaviorNotFoundException;

trait ResolvesBehaviors
{
    /**
     * Get a specific behavior by its path.
     *
     * @param  string  $path  Behavior path (e.g. 'guards.createOrderAction')
     *
     * @throws BehaviorNotFoundException
     */
    public static function getBehavior(string $path): callable|EventBehavior
    {
        $behaviors = static::definition()?->behavior ?? [];

        [$type, $name] = explode('.', $path);

        if (!isset($behaviors[$type][$name])) {
            throw BehaviorNotFoundException::build($path);
        }

        return $behaviors[$type][$name];
    }

    /**
     * Get a calculator behavior.
     */
    public static function getCalculator(string $name): ?callable
    {
        return static::getBehavior(BehaviorType::Calculator->value.'.'.$name);
    }

    /**
     * Get a guard behavior.
     */
    public static function getGuard(string $name): ?callable
    {
        return static::getBehavior(BehaviorType::Guard->value.'.'.$name);
    }

    /**
     * Get an action behavior.
     */
    public static function getAction(string $name): ?callable
    {
        return static::getBehavior(BehaviorType::Action->value.'.'.$name);
    }

    /**
     * Get an event behavior.
     */
    public static function getEvent(string $name): ?EventBehavior
    {
        return static::getBehavior(BehaviorType::Event->value.'.'.$name);
    }
}
