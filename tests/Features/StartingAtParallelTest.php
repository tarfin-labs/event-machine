<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\E2EBasicMachine;

it('activates every region at its initial leaf when starting at a parallel state', function (): void {
    $machine = E2EBasicMachine::startingAt('processing');

    expect($machine->state()->value)->toBe([
        'e2e_basic.processing.region_a.working',
        'e2e_basic.processing.region_b.working',
    ])
        ->and($machine->state()->matches('processing.region_a.working'))->toBeTrue()
        ->and($machine->state()->matches('processing.region_b.working'))->toBeTrue();
});

it('drives parallel gating to @done from startingAt', function (): void {
    $machine = E2EBasicMachine::startingAt('processing')
        ->send('REGION_A_PROCESSED');

    expect($machine->state()->matches('processing.region_a.finished'))->toBeTrue()
        ->and($machine->state()->matches('processing.region_b.working'))->toBeTrue();

    $machine->send('REGION_B_PROCESSED')
        ->assertState('completed')
        ->assertFinished();
});

it('initializes sibling regions when starting at a leaf inside one region', function (): void {
    $machine = E2EBasicMachine::startingAt('e2e_basic.processing.region_a.finished');

    expect($machine->state()->value)->toBe([
        'e2e_basic.processing.region_a.finished',
        'e2e_basic.processing.region_b.working',
    ]);

    // Sibling region is live — completing it satisfies the parallel @done.
    $machine->send('REGION_B_PROCESSED')->assertState('completed');
});

it('accepts machine-relative dotted paths for parallel leaves', function (): void {
    $machine = E2EBasicMachine::startingAt('processing.region_a.finished');

    expect($machine->state()->value)->toBe([
        'e2e_basic.processing.region_a.finished',
        'e2e_basic.processing.region_b.working',
    ]);
});

it('resolves a region id to its initial leaf with siblings initialized', function (): void {
    $machine = E2EBasicMachine::startingAt('processing.region_a');

    expect($machine->state()->value)->toBe([
        'e2e_basic.processing.region_a.working',
        'e2e_basic.processing.region_b.working',
    ]);
});
