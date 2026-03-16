<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Enums;

/**
 * Timer sweep frequency configuration.
 *
 * Maps directly to Laravel Scheduler frequency method names.
 * Used in config/machine.php to control how often the
 * ProcessTimersCommand runs.
 */
enum TimerResolution: string
{
    case EVERY_SECOND          = 'everySecond';
    case EVERY_MINUTE          = 'everyMinute';
    case EVERY_TWO_MINUTES     = 'everyTwoMinutes';
    case EVERY_FIVE_MINUTES    = 'everyFiveMinutes';
    case EVERY_TEN_MINUTES     = 'everyTenMinutes';
    case EVERY_FIFTEEN_MINUTES = 'everyFifteenMinutes';
    case EVERY_THIRTY_MINUTES  = 'everyThirtyMinutes';
}
