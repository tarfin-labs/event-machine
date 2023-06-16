<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

test('an event has a version', function (): void {
    $eventWithoutExplicitVersionDefinition = new class() extends EventBehavior {
        public static function getType(): string
        {
            return 'TEST_EVENT';
        }
    };

    $eventWithVersionDefinition = new class(version: 13) extends EventBehavior {
        public static function getType(): string
        {
            return 'TEST_EVENT';
        }
    };

    expect($eventWithoutExplicitVersionDefinition->version)->toBe(1);
    expect($eventWithVersionDefinition->version)->toBe(13);
});
