<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Reads\GoEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Reads\AtomicityMachine;

test('a persist failure rolls back the action write and does not wedge the machine', function (): void {
    Schema::create('atomicity_probe', function (Blueprint $table): void {
        $table->id();
        $table->string('note');
    });

    $machine = AtomicityMachine::create();
    $machine->persist();
    $rootId = $machine->state->history->first()->root_event_id;

    // Force the persist() upsert to fail when the GO external event row is written — WITHOUT
    // destroying machine_events, so the not-wedged recovery can be verified afterwards.
    DB::statement("CREATE TRIGGER fail_go BEFORE INSERT ON machine_events WHEN NEW.type = 'GO' BEGIN SELECT RAISE(ABORT, 'boom'); END");

    $threw = false;
    try {
        $machine->send(new GoEvent()); // transactional: transition (probe write) + persist (fails)
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeTrue()
        // The action's DB write was rolled back together with the failed persist.
        ->and(DB::table('atomicity_probe')->count())->toBe(0)
        // No orphan GO event was committed.
        ->and(MachineEvent::where('root_event_id', $rootId)->where('type', 'GO')->count())->toBe(0);

    // Not wedged: remove the fault, restore fresh, and a valid send() reaches the expected state.
    DB::statement('DROP TRIGGER fail_go');

    $restored = AtomicityMachine::create(state: $rootId);
    $restored->send(new GoEvent());

    expect($restored->state->matches('done'))->toBeTrue();
});
