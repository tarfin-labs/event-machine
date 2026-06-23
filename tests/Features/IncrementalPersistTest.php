<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
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
