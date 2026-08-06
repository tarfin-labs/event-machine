<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Enums;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;
use Tarfinlabs\EventMachine\Behavior\CalculatorBehavior;

/**
 * Class BehaviorType.
 *
 * The BehaviorType class represents an enumerated type for different types of behaviors.
 */
enum BehaviorType: string
{
    case Calculator = 'calculators';
    case Guard      = 'guards';
    case Action     = 'actions';
    case Output     = 'outputs';
    case Event      = 'events';
    case Context    = 'context';

    /**
     * Returns the behavior class based on the current value of $this.
     *
     * @return string The class name of the behavior.
     */
    public function getBehaviorClass(): string
    {
        return match ($this) {
            self::Calculator => CalculatorBehavior::class,
            self::Guard      => GuardBehavior::class,
            self::Action     => ActionBehavior::class,
            self::Output     => OutputBehavior::class,
            self::Event      => EventBehavior::class,
            self::Context    => ContextManager::class,
        };
    }

    /**
     * The config keys that hold behaviors of this type.
     *
     * A bare FQCN in config carries no type of its own, so the key it sits under is what
     * classifies it. Events and contexts are declared as config *keys* and by a dedicated
     * `context` slot rather than under a behavior-bearing key, so both map to nothing here.
     *
     * This list spans config LEVELS on purpose: `entry`/`exit` are state keys while `actions`
     * is a transition key. It answers "which keys anywhere in a config hold this behavior
     * type", so do not substitute it into a level-specific check — StateConfigValidator's
     * state-action validation legitimately looks at `entry`/`exit` only.
     *
     * @return list<string>
     */
    public function configKeys(): array
    {
        return match ($this) {
            self::Action     => ['entry', 'exit', 'actions'],
            self::Guard      => ['guards'],
            self::Calculator => ['calculators'],
            self::Output     => ['output'],
            self::Event      => [],
            self::Context    => [],
        };
    }

    /**
     * The behavior type a config key holds, or null when the key holds no behavior.
     */
    public static function fromConfigKey(string $key): ?self
    {
        foreach (self::cases() as $case) {
            if (in_array($key, $case->configKeys(), strict: true)) {
                return $case;
            }
        }

        return null;
    }
}
