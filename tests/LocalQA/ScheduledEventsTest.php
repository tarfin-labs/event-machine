<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScheduledMachines\ScheduledMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScheduledMachines\ExpiredApplicationsResolver;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    ExpiredApplicationsResolver::$ids = null;
});

it('LocalQA: schedule resolver dispatches Bus::batch via Horizon', function (): void {
    $ids = [];
    for ($i = 0; $i < 2; $i++) {
        $m = ScheduledMachine::create();
        $m->persist();
        $ids[] = $m->state->history->first()->root_event_id;
    }

    ExpiredApplicationsResolver::setUp($ids);

    Artisan::call('machine:process-scheduled', [
        '--class' => ScheduledMachine::class,
        '--event' => 'CHECK_EXPIRY',
    ]);

    $allExpired = LocalQATestCase::waitFor(function () use ($ids) {
        foreach ($ids as $id) {
            $cs = MachineCurrentState::where('root_event_id', $id)->first();
            if (!$cs || !str_contains($cs->state_id, 'expired')) {
                return false;
            }
        }

        return true;
    }, timeoutSeconds: 45);

    expect($allExpired)->toBeTrue('Schedule Bus::batch not processed by Horizon');

    $batch = DB::table('job_batches')
        ->where('name', 'LIKE', '%ScheduledMachine%CHECK_EXPIRY%')
        ->first();
    expect($batch)->not->toBeNull();
});

it('LocalQA: auto-detect null resolver dispatches to all instances via Horizon', function (): void {
    $ids = [];
    for ($i = 0; $i < 2; $i++) {
        $m = ScheduledMachine::create();
        $m->persist();
        $ids[] = $m->state->history->first()->root_event_id;
    }

    Artisan::call('machine:process-scheduled', [
        '--class' => ScheduledMachine::class,
        '--event' => 'DAILY_REPORT',
    ]);

    $processed = LocalQATestCase::waitFor(function () use ($ids) {
        foreach ($ids as $id) {
            $has = DB::table('machine_events')
                ->where('root_event_id', $id)
                ->where('type', 'DAILY_REPORT')
                ->exists();
            if (!$has) {
                return false;
            }
        }

        return true;
    }, timeoutSeconds: 45);

    expect($processed)->toBeTrue('Auto-detect DAILY_REPORT not processed via Horizon');
});

it('LocalQA: schedule cross-check filters wrong machine class', function (): void {
    $order = ScheduledMachine::create();
    $order->persist();
    $orderId = $order->state->history->first()->root_event_id;

    // Insert a fake instance for different machine class
    MachineCurrentState::insert([
        'root_event_id'    => 'fake-other-class',
        'machine_class'    => 'App\\Wrong\\Machine',
        'state_id'         => 'some_state',
        'state_entered_at' => now(),
    ]);

    ExpiredApplicationsResolver::setUp([$orderId, 'fake-other-class']);

    Artisan::call('machine:process-scheduled', [
        '--class' => ScheduledMachine::class,
        '--event' => 'CHECK_EXPIRY',
    ]);

    $orderExpired = LocalQATestCase::waitFor(function () use ($orderId) {
        $cs = MachineCurrentState::where('root_event_id', $orderId)->first();

        return $cs && str_contains($cs->state_id, 'expired');
    }, timeoutSeconds: 45);

    expect($orderExpired)->toBeTrue('Order not expired after cross-check');

    // Fake instance unaffected
    $fakeCs = MachineCurrentState::where('root_event_id', 'fake-other-class')->first();
    expect($fakeCs->state_id)->toBe('some_state');
});
