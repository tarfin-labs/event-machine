<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioFrontierMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioOrderingMachine;

test('the multi-path listing shows each path weight', function (): void {
    // Five routes reach approved, so the selection listing renders. The cheapest three weigh 3
    // and the parallel one weighs 7 — both must appear beside the existing counts.
    $this->artisan('machine:scenario', [
        'name'      => 'AtApprovedWeighted',
        'machine'   => ScenarioOrderingMachine::class,
        'source'    => 'pending',
        'event'     => 'SUBMIT',
        'target'    => 'approved',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('@continue, weight 3')
        ->expectsOutputToContain('@continue, weight 7')
        // Paired with the single-path test below, which asserts this line is ABSENT. Without an
        // assertion on both sides, deleting the hint outright would leave the suite green.
        ->expectsOutputToContain('Use --path=N to select. Using path [0].')
        ->assertSuccessful();
});

test('the single-path listing shows that path weight too', function (): void {
    // entry --DIRECT--> goal is the only route, and the listing used to be gated on there
    // being more than one: a single-path resolution printed no listing, and so no weight at
    // all. The selection hint is the one part that stays behind, having nothing to select.
    $this->artisan('machine:scenario', [
        'name'      => 'AtGoalDirect',
        'machine'   => ScenarioFrontierMachine::class,
        'source'    => 'entry',
        'event'     => 'DIRECT',
        'target'    => 'goal',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Found 1 path from entry to goal:')
        ->expectsOutputToContain(', weight 0')
        ->doesntExpectOutputToContain('Use --path=N to select.')
        ->assertSuccessful();
});
