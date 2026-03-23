<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    config([
        'machine.scenarios.enabled' => true,
        'machine.scenarios.path'    => __DIR__.'/../../Stubs/Scenarios',
    ]);
});

it('returns failure when scenarios are disabled', function (): void {
    config(['machine.scenarios.enabled' => false]);

    $exitCode = Artisan::call('machine:scenario', ['--list' => true]);

    expect($exitCode)->toBe(1);
});

it('lists scenarios when enabled', function (): void {
    $exitCode = Artisan::call('machine:scenario', ['--list' => true]);

    expect($exitCode)->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('TrafficLightsActiveScenario');
    expect($output)->toContain('TrafficLightsIncrementedScenario');
});

it('returns failure for unknown scenario', function (): void {
    $exitCode = Artisan::call('machine:scenario', ['scenario' => 'NonexistentScenario']);

    expect($exitCode)->toBe(1);
});
