<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\MachineActor;
use Tarfinlabs\EventMachine\Tests\Stubs\Models\ModelA;

it('can persist the machine state', function (): void {

    /** @var ModelA $a */
    $modelA = ModelA::create([
        'value' => 'some value',
    ]);

    $modelA->abc_mre->persist();

    $modelA->traffic_mre->send(['type' => 'INC'], shouldPersist: false);
    $modelA->traffic_mre->send(['type' => 'INC'], shouldPersist: false);
    $modelA->traffic_mre->send(['type' => 'INC'], shouldPersist: false);

    $modelA->traffic_mre->persist();

    expect($modelA->abc_mre)->toBeInstanceOf(MachineActor::class);
    expect($modelA->traffic_mre)->toBeInstanceOf(MachineActor::class);

    expect(['abc_mre' => $modelA->abc_mre->state->history->first()->root_event_id])
        ->toBeInDatabase(ModelA::class);

    expect(['traffic_mre' => $modelA->traffic_mre->state->history->first()->root_event_id])
        ->toBeInDatabase(ModelA::class);
});
