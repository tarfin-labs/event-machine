<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\ScenarioPath;
use Tarfinlabs\EventMachine\Analysis\ScenarioPathStep;
use Tarfinlabs\EventMachine\Analysis\StateClassification;

test('signature() produces readable format', function (): void {
    $path = new ScenarioPath([
        new ScenarioPathStep(stateRoute: 'routing', stateKey: 'routing', classification: StateClassification::TRANSIENT, event: '@always'),
        new ScenarioPathStep(stateRoute: 'processing', stateKey: 'processing', classification: StateClassification::DELEGATION, event: '@always'),
        new ScenarioPathStep(stateRoute: 'reviewing', stateKey: 'reviewing', classification: StateClassification::INTERACTIVE, event: '@done'),
    ]);

    $signature = $path->signature();

    expect($signature)->toContain('routing')
        ->and($signature)->toContain('processing')
        ->and($signature)->toContain('reviewing')
        ->and($signature)->toContain('→');
});

test('stats() counts overrides, outcomes, continues correctly', function (): void {
    $path = new ScenarioPath([
        new ScenarioPathStep(stateRoute: 'routing', stateKey: 'routing', classification: StateClassification::TRANSIENT),
        new ScenarioPathStep(stateRoute: 'processing', stateKey: 'processing', classification: StateClassification::DELEGATION),
        new ScenarioPathStep(stateRoute: 'reviewing', stateKey: 'reviewing', classification: StateClassification::INTERACTIVE),
        new ScenarioPathStep(stateRoute: 'approved', stateKey: 'approved', classification: StateClassification::FINAL),
    ]);

    $stats = $path->stats();

    expect($stats['overrides'])->toBe(1)   // TRANSIENT
        ->and($stats['outcomes'])->toBe(1)  // DELEGATION
        ->and($stats['continues'])->toBe(1); // INTERACTIVE
});

test('empty steps produces empty signature', function (): void {
    $path = new ScenarioPath([]);

    expect($path->signature())->toBe('')
        ->and($path->stats())->toBe(['overrides' => 0, 'outcomes' => 0, 'continues' => 0]);
});
