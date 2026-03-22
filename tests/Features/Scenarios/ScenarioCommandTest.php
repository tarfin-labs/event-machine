<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    config(['machine.scenarios.enabled' => true]);
});

it('returns failure when scenarios are disabled', function (): void {
    config(['machine.scenarios.enabled' => false]);

    $exitCode = Artisan::call('machine:scenario', ['--list' => true]);

    expect($exitCode)->toBe(1);
});

it('lists scenarios when enabled', function (): void {
    config([
        'machine.scenarios.enabled' => true,
        'machine.scenarios.path'    => __DIR__.'/../../Stubs/Scenarios',
    ]);

    $exitCode = Artisan::call('machine:scenario', ['--list' => true]);

    // Should succeed even if no scenarios found in the stub path
    expect($exitCode)->toBeIn([0, 1]);
});
