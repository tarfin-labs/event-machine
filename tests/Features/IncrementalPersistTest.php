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

test('persist stays bounded as history grows large (placeholder ceiling cannot be reached)', function (): void {
    $machine = ReadsMachine::create();
    $machine->send('SUBMIT');                // real history, persisted, at 'processing'
    $rootId   = $machine->state->history->first()->root_event_id;
    $template = MachineEvent::where('root_event_id', $rootId)->orderBy('sequence_number')->get()->last();

    // Pad the history with many rows. Each filler is a structurally-valid 'processing' row, so
    // restore (which reads the highest-sequence row) still recovers the correct state/context.
    // (The literal >=5462-row MySQL placeholder-ceiling regression lives in tests/LocalQA.)
    $raw      = $template->getRawOriginal();
    $startSeq = (int) MachineEvent::where('root_event_id', $rootId)->max('sequence_number');

    $rows = [];
    for ($i = 1; $i <= 600; $i++) {
        $row                    = $raw;
        $row['id']              = (string) Str::ulid();
        $row['sequence_number'] = $startSeq + $i;
        $row['type']            = 'filler';
        $rows[]                 = $row;
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        MachineEvent::insert($chunk);
    }

    $historySize = MachineEvent::where('root_event_id', $rootId)->count();

    // Send another event and capture the persist upsert. The number of rows it writes must stay
    // bounded (dirty slice = new events + previous tail), independent of the history size — that
    // is exactly what keeps a long history clear of the prepared-statement placeholder ceiling.
    $restored = ReadsMachine::create(state: $rootId);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $restored->send('COMPLETE');
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $maxUpsertRows = 0;
    foreach ($log as $entry) {
        if (str_contains($entry['query'], 'machine_events') && str_contains(strtolower($entry['query']), 'insert')) {
            $maxUpsertRows = max($maxUpsertRows, substr_count($entry['query'], '), ('));
        }
    }

    expect($restored->state->matches('completed'))->toBeTrue()
        ->and($historySize)->toBeGreaterThan(600)
        // The upsert touched only a handful of rows, not the whole (600+) history.
        ->and($maxUpsertRows)->toBeLessThan(50);
});
