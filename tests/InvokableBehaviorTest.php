<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;

test('invokable behavior shouldLog property defaults to false', function (): void {
    $behavior = new class() extends InvokableBehavior {
        public function __invoke(): void {}
    };

    // Test that shouldLog defaults to false (not true)
    expect($behavior->shouldLog)->toBeFalse();
    expect($behavior->shouldLog)->not->toBeTrue();
});
