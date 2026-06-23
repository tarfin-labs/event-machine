<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Reads\GoEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Reads\AtomicityMachine;

test('a persist failure for a transactional event rolls back the action DB write', function (): void {
    Schema::create('atomicity_probe', function (Blueprint $table): void {
        $table->id();
        $table->string('note');
    });

    $machine = AtomicityMachine::create();
    $machine->persist(); // initial events persisted while machine_events still exists

    // Break persistence so the next persist() (inside the transition transaction) throws.
    Schema::drop('machine_events');

    $threw = false;
    try {
        $machine->send(new GoEvent()); // transactional: transition (probe insert) + persist (fails)
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeTrue()
        // The action's DB write was rolled back together with the failed persist.
        ->and(DB::table('atomicity_probe')->count())->toBe(0);
});
