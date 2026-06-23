<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Reads\ReadsMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
});

/*
 * The MySQL-specific regression behind the persist write-path hardening.
 *
 * Production incident: a machine's history bloated to ~5,458 rows. The OLD persist()
 * re-upserted the ENTIRE history every event — one prepared statement, 12 columns per row —
 * and hit MySQL's hard limit of 65,535 placeholders at ~5,462 rows (5,462 × 12 = 65,544).
 * The next event threw QueryException and wedged the machine. Incremental persist upserts
 * only the dirty slice, so a long history persists fine.
 *
 * This is intentionally a LocalQA (MySQL) test: SQLite's placeholder limit differs, so the
 * unit suite can only prove the upsert stays *bounded* — the literal ceiling regression must
 * run against MySQL.
 */
it('LocalQA: a >=5462-row history persists a new event without exhausting MySQL placeholders', function (): void {
    $machine = ReadsMachine::create();
    $machine->send('SUBMIT'); // real history at 'processing', persisted
    $rootId   = $machine->state->history->first()->root_event_id;
    $template = MachineEvent::where('root_event_id', $rootId)->orderBy('sequence_number')->get()->last();

    // Pad to >= 5462 rows. Each filler is a structurally-valid 'processing' row (same
    // machine_value + full context as the real tail), so restore — which reads the
    // highest-sequence row — still recovers the correct state and context.
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
    // Chunk so the seed inserts themselves stay under the 65,535-placeholder limit.
    foreach (array_chunk($rows, 1000) as $chunk) {
        MachineEvent::insert($chunk);
    }

    expect(MachineEvent::where('root_event_id', $rootId)->count())->toBeGreaterThanOrEqual(5462);

    // Under the OLD full-history upsert this send() throws QueryException; incremental persist
    // writes only the dirty slice (previous tail + new rows), so it succeeds.
    $restored = ReadsMachine::create(state: $rootId);
    $restored->send('COMPLETE');

    $reloaded = ReadsMachine::create(state: $rootId);

    expect($restored->state->matches('completed'))->toBeTrue()
        ->and($reloaded->state->matches('completed'))->toBeTrue()
        ->and($reloaded->state->context->get('orderId'))->toBe('ORD-1');
});
