<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Reads\ReadsMachine;

test('sequence_number survives pruning — derived from max+1, no duplicates', function (): void {
    $machine = ReadsMachine::create();
    $machine->send('SUBMIT');
    $rootId = $machine->state->history->first()->root_event_id;

    $sequences = MachineEvent::where('root_event_id', $rootId)
        ->orderBy('sequence_number')
        ->pluck('sequence_number')
        ->all();

    // Prune a middle row (compaction): delete a non-first, non-last sequence.
    $middle = $sequences[(int) floor(count($sequences) / 2)];
    MachineEvent::where('root_event_id', $rootId)
        ->where('sequence_number', $middle)
        ->delete();

    $maxBefore = (int) MachineEvent::where('root_event_id', $rootId)->max('sequence_number');

    // Restore (reads only the last row — pruning the middle is safe) and append.
    ReadsMachine::create(state: $rootId)->send('COMPLETE');

    $allSequences = MachineEvent::where('root_event_id', $rootId)
        ->pluck('sequence_number')
        ->all();

    // No duplicate sequence numbers, and the appended events continue from max+1.
    expect($allSequences)->toHaveCount(count(array_unique($allSequences)))
        ->and(max($allSequences))->toBeGreaterThan($maxBefore);
});
