<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Tests\Stubs\Models\ModelA;

it('can persist the machine state', function (): void {
    /** @var ModelA $a */
    $modelA = ModelA::create([
        'value' => 'some value',
    ]);

    $modelA->traffic_mre->send(['type' => 'INC']);
    $modelA->traffic_mre->send(['type' => 'INC']);
    $modelA->traffic_mre->send(['type' => 'INC']);

    $modelA->traffic_mre->persist();

    expect($modelA->abc_mre)->toBeInstanceOf(Machine::class);
    expect($modelA->traffic_mre)->toBeInstanceOf(Machine::class);

    $this->assertDatabaseHas(ModelA::class, [
        'abc_mre' => null,
    ]);

    $this->assertDatabaseHas(ModelA::class, [
        'traffic_mre' => $modelA->traffic_mre->state->history->first()->root_event_id,
    ]);
});
