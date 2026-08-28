<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Scenarios\ScenarioPlayer;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Exceptions\ScenarioConfigurationException;
use Tarfinlabs\EventMachine\Fixtures\ScenarioDrift\DriftingParamScenario;
use Tarfinlabs\EventMachine\Fixtures\ScenarioDrift\PersistingScenarioMachine;
use Tarfinlabs\EventMachine\Fixtures\ScenarioDrift\ThrowsWhenHydratedScenario;

/*
 * A scenario is a QA aid. Nothing about one may make a machine unloadable.
 *
 * It could: params were re-validated on every restore, so a rule that reads the world — `exists`
 * pointing at a row someone later deleted, `after_or_equal:today` once midnight passes — went from
 * passing to failing with nothing about the machine having changed. The machine then threw on
 * every load, permanently, because the code that clears the stored scenario runs after the restore
 * and could never be reached. The only way out was a direct UPDATE on machine_current_states.
 *
 * The two scenario fixtures live in fixtures/ rather than here: tests/ is PSR-4, so a second class
 * declared in a test file fails `composer dump-autoload --strict-psr`, which the quality gate runs.
 */

beforeEach(function (): void {
    // The restore path that hydrates a stored scenario sits behind this flag. Without it the
    // two restore tests below would pass for the wrong reason: the block is never entered.
    config()->set('machine.scenarios.enabled', true);
    ScenarioPlayer::cleanupOverrides();
});

afterEach(function (): void {
    ScenarioPlayer::cleanupOverrides();
});

test('stored params are hydrated without being validated again', function (): void {
    // The core of it: hydrateParams() with validate:false must not run the rules. Passing a
    // value that would fail them proves the rules did not run, and the value still lands.
    $scenario = new DriftingParamScenario();

    $scenario->hydrateParams(['pickedValue' => 'not-an-integer'], validate: false);

    expect($scenario->validatedParams())->toBe(['pickedValue' => 'not-an-integer']);
});

test('params are still validated when they are accepted', function (): void {
    // The control, and the half that must not change: validation belongs to the moment the
    // params are accepted. Dropping it there would be a different bug.
    $scenario = new DriftingParamScenario();

    expect(fn () => $scenario->hydrateParams(['pickedValue' => 'not-an-integer']))
        ->toThrow(ScenarioConfigurationException::class);
});

test('a machine restores even when its stored scenario class has gone', function (): void {
    // The guarantee, stated as a test: whatever is wrong with the stored scenario, the machine
    // loads. Here the class name persisted no longer resolves to anything.
    $machine = PersistingScenarioMachine::create();
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    $updated = MachineCurrentState::where('root_event_id', $rootEventId)
        ->update(['scenario_class' => 'App\\Gone\\DeletedScenario', 'scenario_params' => ['a' => 1]]);

    // The precondition. Without a persisted row carrying scenario_class there is nothing for
    // the restore path to hydrate, and this test would pass without exercising anything.
    expect($updated)->toBeGreaterThan(0);

    $thrown = null;

    try {
        PersistingScenarioMachine::create(state: $rootEventId);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeNull();
});

test('a machine restores even when hydrating the stored scenario throws', function (): void {
    // The class resolves and is a scenario, but constructing or hydrating it fails. Before the
    // guard this reached the caller as a 500 that no request could clear.
    $machine = PersistingScenarioMachine::create();
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    MachineCurrentState::where('root_event_id', $rootEventId)->update([
        'scenario_class'  => ThrowsWhenHydratedScenario::class,
        'scenario_params' => ['pickedValue' => 1],
    ]);

    $thrown = null;

    try {
        PersistingScenarioMachine::create(state: $rootEventId);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeNull();
});
