<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Scenarios\ScenarioDiscovery;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AbcMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;

beforeEach(function (): void {
    ScenarioDiscovery::resetCache();
});

// ── forMachine ───────────────────────────────────────────────────────────────

test('forMachine discovers scenarios from Scenarios/ directory', function (): void {
    $scenarios = ScenarioDiscovery::forMachine(ScenarioTestMachine::class);

    expect($scenarios)->not->toBeEmpty()
        ->and($scenarios->count())->toBeGreaterThanOrEqual(5); // We have 11 scenario stubs
});

test('forMachine returns empty when no Scenarios/ directory', function (): void {
    // AbcMachine lives in tests/Stubs/Machines/ which has no Scenarios/ subdirectory
    $scenarios = ScenarioDiscovery::forMachine(AbcMachine::class);

    expect($scenarios)->toBeEmpty();
});

test('forMachine skips abstract scenario classes', function (): void {
    // MachineScenario itself is abstract and lives in src/ not in Scenarios/
    // All discovered classes should be concrete
    $scenarios = ScenarioDiscovery::forMachine(ScenarioTestMachine::class);

    $scenarios->each(function (MachineScenario $scenario): void {
        $reflection = new ReflectionClass($scenario);
        expect($reflection->isAbstract())->toBeFalse();
    });
});

test('forMachine scans recursively (nested subdirectories)', function (): void {
    // Our scenarios are in a flat Scenarios/ directory, but discovery uses RecursiveDirectoryIterator
    // This test verifies the recursive scan doesn't crash and finds scenarios
    $scenarios = ScenarioDiscovery::forMachine(ScenarioTestMachine::class);
    expect($scenarios)->not->toBeEmpty();
});

test('forMachine caches result — second call returns same collection', function (): void {
    $first  = ScenarioDiscovery::forMachine(ScenarioTestMachine::class);
    $second = ScenarioDiscovery::forMachine(ScenarioTestMachine::class);

    expect($first->count())->toBe($second->count());
});

test('resetCache clears cache — next call rediscovers', function (): void {
    ScenarioDiscovery::forMachine(ScenarioTestMachine::class);
    ScenarioDiscovery::resetCache();

    // After reset, forMachine should work again (rediscover)
    $scenarios = ScenarioDiscovery::forMachine(ScenarioTestMachine::class);
    expect($scenarios)->not->toBeEmpty();
});

test('cache is keyed by machineClass — different machines don\'t share cache', function (): void {
    $main = ScenarioDiscovery::forMachine(ScenarioTestMachine::class);
    $abc  = ScenarioDiscovery::forMachine(AbcMachine::class);

    // ScenarioTestMachine has scenarios, AbcMachine has none — different counts
    expect($main->count())->toBeGreaterThan(0)
        ->and($abc->count())->toBe(0);
});

// ── forStateAndEvent ─────────────────────────────────────────────────────────

test('forStateAndEvent filters by source state', function (): void {
    $scenarios = ScenarioDiscovery::forStateAndEvent(ScenarioTestMachine::class, 'reviewing');

    // ContinueLoopScenario has source='reviewing'
    expect($scenarios)->not->toBeEmpty();
    $scenarios->each(fn (MachineScenario $s) => expect($s->source())->toBe('reviewing'));
});

test('forStateAndEvent suffix matching on source', function (): void {
    // Use full route including machine prefix
    $scenarios = ScenarioDiscovery::forStateAndEvent(ScenarioTestMachine::class, 'scenario_test.reviewing');

    expect($scenarios)->not->toBeEmpty();
});

test('forStateAndEvent filters by eventType (resolved)', function (): void {
    $scenarios = ScenarioDiscovery::forStateAndEvent(ScenarioTestMachine::class, 'reviewing', 'APPROVE');

    // ContinueLoopScenario has event=ApproveEvent::class → eventType='APPROVE'
    $scenarios->each(fn (MachineScenario $s) => expect($s->eventType())->toBe('APPROVE'));
});

test('forStateAndEvent without eventType returns all matching source', function (): void {
    $withEvent    = ScenarioDiscovery::forStateAndEvent(ScenarioTestMachine::class, 'reviewing', 'APPROVE');
    $withoutEvent = ScenarioDiscovery::forStateAndEvent(ScenarioTestMachine::class, 'reviewing');

    expect($withoutEvent->count())->toBeGreaterThanOrEqual($withEvent->count());
});

// ── resolveBySlug ────────────────────────────────────────────────────────────

test('resolveBySlug finds by kebab-case slug', function (): void {
    $scenario = ScenarioDiscovery::resolveBySlug(ScenarioTestMachine::class, 'happy-path-scenario');

    expect($scenario)->not->toBeNull()
        ->and($scenario->slug())->toBe('happy-path-scenario');
});

test('resolveBySlug returns null for unknown slug', function (): void {
    $scenario = ScenarioDiscovery::resolveBySlug(ScenarioTestMachine::class, 'nonexistent-slug');

    expect($scenario)->toBeNull();
});

// ── groupedByEvent ───────────────────────────────────────────────────────────

test('groupedByEvent groups by resolved event type (not FQCN)', function (): void {
    $grouped = ScenarioDiscovery::groupedByEvent(ScenarioTestMachine::class, 'reviewing');

    // Should be keyed by event type strings like 'APPROVE', not by FQCN
    expect($grouped)->toBeArray();
    foreach (array_keys($grouped) as $eventType) {
        expect($eventType)->not->toContain('\\'); // Should be type string, not FQCN
    }
});

// ── serializeParams ──────────────────────────────────────────────────────────

test('serializeParams auto-derives required flag from rules', function (): void {
    $grouped = ScenarioDiscovery::groupedByEvent(ScenarioTestMachine::class, 'idle');

    // Find ParameterizedScenario in the grouped results
    $found = false;
    foreach ($grouped as $scenarios) {
        foreach ($scenarios as $scenarioInfo) {
            if ($scenarioInfo['slug'] === 'parameterized-scenario') {
                $found  = true;
                $params = $scenarioInfo['params'];

                // 'amount' has ['required', 'integer', 'min:1'] — required=true
                expect($params['amount']['required'])->toBeTrue();

                // 'note' has rich definition with rules ['nullable', 'string', 'max:255'] — required=false
                expect($params['note']['required'])->toBeFalse();
            }
        }
    }

    expect($found)->toBeTrue();
});
