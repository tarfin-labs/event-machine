<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Reads\ReadsMachine;

test('persist upserts only the dirty slice, not the whole history', function (): void {
    $machine = ReadsMachine::create();
    $machine->send('SUBMIT');               // persists start + submit events
    $rootId = $machine->state->history->first()->root_event_id;

    $restored     = ReadsMachine::create(state: $rootId);
    $restoredIds  = $restored->state->history->pluck('id')->all();
    $previousTail = $restored->state->history->last()->id;

    DB::flushQueryLog();
    DB::enableQueryLog();
    $restored->send('COMPLETE');            // appends + persists incrementally
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    // Collect which already-loaded ids the persist upsert re-wrote.
    $writtenIds = [];
    foreach ($log as $entry) {
        if (!str_contains($entry['query'], 'machine_events') || !str_contains(strtolower($entry['query']), 'insert')) {
            continue;
        }
        foreach ($entry['bindings'] as $binding) {
            if (is_string($binding) && in_array($binding, $restoredIds, true)) {
                $writtenIds[] = $binding;
            }
        }
    }

    // The previous tail is re-written (downgraded full→diff); earlier rows are not.
    $earlyIds = array_slice($restoredIds, 0, count($restoredIds) - 1);

    expect($writtenIds)->toContain($previousTail)
        ->and(array_diff($earlyIds, $writtenIds))->not->toBeEmpty();
});

test('incremental persist preserves context round-trips across restores', function (): void {
    $machine = ReadsMachine::create();
    $machine->send('SUBMIT');
    $rootId = $machine->state->history->first()->root_event_id;

    ReadsMachine::create(state: $rootId)->send('COMPLETE');

    $restored = ReadsMachine::create(state: $rootId);

    expect($restored->state->matches('completed'))->toBeTrue()
        ->and($restored->state->context->get('orderId'))->toBe('ORD-1')
        ->and($restored->state->context->get('total'))->toBe(100);
});

test('a machine with a very large history persists a further event without exhausting placeholders', function (): void {
    $machine = ReadsMachine::create();
    $machine->send('SUBMIT');                // real history, persisted, at 'processing'
    $rootId   = $machine->state->history->first()->root_event_id;
    $template = MachineEvent::where('root_event_id', $rootId)->orderBy('sequence_number')->get()->last();

    // Pad to >= 5462 rows (5462 × 12 columns > MySQL's 65,535 placeholder ceiling — the
    // production failure mode). Each filler is a structurally-valid 'processing' row, so restore
    // (which reads the highest-sequence row) still recovers the correct state and full context.
    $raw      = $template->getRawOriginal();
    $startSeq = (int) MachineEvent::where('root_event_id', $rootId)->max('sequence_number');

    $rows = [];
    for ($i = 1; $i <= 5500; $i++) {
        $row                    = $raw;
        $row['id']              = (string) Str::ulid();
        $row['sequence_number'] = $startSeq + $i;
        $row['type']            = 'filler';
        $rows[]                 = $row;
    }
    // Chunk the seed insert so the seeding itself stays under SQLite's variable limit.
    foreach (array_chunk($rows, 400) as $chunk) {
        MachineEvent::insert($chunk);
    }

    expect(MachineEvent::where('root_event_id', $rootId)->count())->toBeGreaterThanOrEqual(5462);

    // The old full-history upsert would exceed the placeholder ceiling here; the incremental
    // write touches only the dirty slice, so this succeeds and the state still advances.
    $restored = ReadsMachine::create(state: $rootId);
    $restored->send('COMPLETE');

    expect($restored->state->matches('completed'))->toBeTrue();
});
