<?php

declare(strict_types=1);

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
        ->assertSuccessful();
});
