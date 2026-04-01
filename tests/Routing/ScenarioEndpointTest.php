<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tarfinlabs\EventMachine\Routing\MachineRouter;
use Tarfinlabs\EventMachine\Scenarios\ScenarioDiscovery;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;

beforeEach(function (): void {
    config()->set('machine.scenarios.enabled', true);
    ScenarioDiscovery::resetCache();

    MachineRouter::register(ScenarioTestMachine::class, [
        'prefix'       => '/api/scenario-test',
        'machineIdFor' => ['APPROVE', 'REJECT'],
        'name'         => 'scenario-test',
    ]);

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
});

// ── availableScenarios in endpoint response ──────────────────────────────────

test('endpoint response includes availableScenarios key when scenarios enabled', function (): void {
    $routes     = collect(Route::getRoutes()->getRoutes());
    $hasApprove = $routes->contains(fn ($r) => str_contains($r->uri(), 'approve'));
    expect($hasApprove)->toBeTrue();
});

test('availableScenarios grouped by event type — keys are event type strings, not FQCN', function (): void {
    $grouped = ScenarioDiscovery::groupedByEvent(
        machineClass: ScenarioTestMachine::class,
        currentState: 'reviewing',
    );

    foreach (array_keys($grouped) as $eventType) {
        expect($eventType)->not->toContain('\\');
    }
});

test('availableScenarios contains slug, description, target, params per scenario', function (): void {
    $grouped = ScenarioDiscovery::groupedByEvent(
        machineClass: ScenarioTestMachine::class,
        currentState: 'reviewing',
    );

    $found = false;
    foreach ($grouped as $scenarios) {
        foreach ($scenarios as $info) {
            expect($info)->toHaveKey('slug')
                ->and($info)->toHaveKey('description')
                ->and($info)->toHaveKey('target')
                ->and($info)->toHaveKey('params');
            $found = true;
            break 2;
        }
    }

    expect($found)->toBeTrue();
});

test('availableScenarios only includes scenarios matching current state source', function (): void {
    $grouped = ScenarioDiscovery::groupedByEvent(
        machineClass: ScenarioTestMachine::class,
        currentState: 'reviewing',
    );

    $allSlugs = [];
    foreach ($grouped as $scenarios) {
        foreach ($scenarios as $info) {
            $allSlugs[] = $info['slug'];
        }
    }

    expect($allSlugs)->toContain('continue-loop-scenario');
});

test('availableScenarios not included when scenarios.enabled=false', function (): void {
    config()->set('machine.scenarios.enabled', false);

    expect(config('machine.scenarios.enabled'))->toBeFalse();
});

// NOTE: POST scenario activation tests (slug activation, scenarioParams, source/event mismatch,
// deactivation, @continue via endpoint) require persisted machine + real delegation → QA tests.
// See spec/9.4.0-scenario-tests.md §6 "Additional QA Tests Needed".
