<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Models\MachineTimerFire;
use Tarfinlabs\EventMachine\Definition\TimerDefinition;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Sweep command that processes time-based events (after/every on transitions).
 *
 * Runs on a schedule (default: every minute) via MachineServiceProvider.
 * Finds machine instances in states with timer-configured transitions,
 * checks timing conditions, and dispatches SendToMachineJob for eligible instances.
 */
class ProcessTimersCommand extends Command
{
    protected $signature = 'machine:process-timers
        {--class= : Process only this machine class (for sharding)}';
    protected $description = 'Process time-based events (after/every) for machine instances';

    public function handle(): int
    {
        // Backpressure check
        $threshold = config('machine.timers.backpressure_threshold', 10000);
        if (Queue::size() > $threshold) {
            $this->warn("Timer sweep skipped: queue backpressure ({$threshold} threshold exceeded).");

            return self::SUCCESS;
        }

        $machineClass = (string) $this->option('class');
        $batchSize    = (int) config('machine.timers.batch_size', 100);

        if ($machineClass !== '') {
            $this->processClass($machineClass, $batchSize);
        } else {
            $this->warn('No --class specified. Use MachineServiceProvider auto-registration for per-class sharding.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function processClass(string $machineClass, int $batchSize): void
    {
        try {
            $definition = $machineClass::definition();
        } catch (\Throwable $e) {
            $this->error("Failed to load definition for {$machineClass}: {$e->getMessage()}");

            return;
        }

        $timerDefinitions = $this->collectTimerDefinitions($definition);

        if ($timerDefinitions === []) {
            return;
        }

        foreach ($timerDefinitions as $timerDef) {
            if ($timerDef->isAfter()) {
                $this->processAfterTimer($machineClass, $timerDef, $batchSize);
            } elseif ($timerDef->isEvery()) {
                $this->processEveryTimer($machineClass, $timerDef, $batchSize);
            }
        }
    }

    /**
     * Collect all TimerDefinitions from a machine definition's transitions.
     *
     * @return array<TimerDefinition>
     */
    protected function collectTimerDefinitions(MachineDefinition $definition): array
    {
        $timers = [];

        foreach ($definition->idMap as $stateDefinition) {
            if ($stateDefinition->transitionDefinitions === null) {
                continue;
            }

            foreach ($stateDefinition->transitionDefinitions as $transitionDef) {
                if ($transitionDef->timerDefinition instanceof TimerDefinition) {
                    $timers[] = $transitionDef->timerDefinition;
                }
            }
        }

        return $timers;
    }

    /**
     * Process an `after` timer — one-shot, fires once per state entry.
     */
    protected function processAfterTimer(string $machineClass, TimerDefinition $timer, int $batchSize): void
    {
        $deadline = now()->subSeconds($timer->delaySeconds);

        // Find instances in target state, past deadline, not yet fired
        $instances = MachineCurrentState::query()
            ->where('machine_class', $machineClass)
            ->where('state_id', $timer->stateId)
            ->where('state_entered_at', '<=', $deadline)
            ->whereNotExists(function (Builder $query) use ($timer): void {
                $query->from('machine_timer_fires')
                    ->whereColumn('machine_timer_fires.root_event_id', 'machine_current_states.root_event_id')
                    ->where('machine_timer_fires.timer_key', $timer->key())
                    ->where('machine_timer_fires.status', MachineTimerFire::STATUS_FIRED);
            })
            ->limit($batchSize)
            ->get();

        if ($instances->isEmpty()) {
            return;
        }

        // Atomic dedup: insert fire records BEFORE dispatching to prevent race conditions.
        // Only dispatch for instances where the insert actually succeeded (not already fired).
        $toDispatch = collect();

        foreach ($instances as $instance) {
            $inserted = DB::table('machine_timer_fires')->insertOrIgnore([
                'root_event_id' => $instance->root_event_id,
                'timer_key'     => $timer->key(),
                'last_fired_at' => now(),
                'fire_count'    => 1,
                'status'        => MachineTimerFire::STATUS_FIRED,
            ]);

            if ($inserted > 0) {
                $toDispatch->push($instance);
            }
        }

        if ($toDispatch->isNotEmpty()) {
            $this->dispatchTimerJobs($machineClass, $timer->eventName, $toDispatch);
        }
    }

    /**
     * Process an `every` timer — recurring, fires at intervals.
     */
    protected function processEveryTimer(string $machineClass, TimerDefinition $timer, int $batchSize): void
    {
        // Handle max/then: find instances at max count
        if ($timer->max !== null && $timer->then !== null) {
            $this->processEveryMaxThen($machineClass, $timer, $batchSize);
        }

        // Find instances due for next fire (DB::table for cross-table JOIN)
        $instances = DB::table('machine_current_states')
            ->select('machine_current_states.*')
            ->where('machine_current_states.machine_class', $machineClass)
            ->where('machine_current_states.state_id', $timer->stateId)
            ->leftJoin('machine_timer_fires', function (JoinClause $join) use ($timer): void {
                $join->on('machine_timer_fires.root_event_id', '=', 'machine_current_states.root_event_id')
                    ->where('machine_timer_fires.timer_key', '=', $timer->key());
            })
            ->where(function (Builder $query): void {
                $query->whereNull('machine_timer_fires.status')
                    ->orWhere('machine_timer_fires.status', MachineTimerFire::STATUS_ACTIVE);
            })
            ->whereRaw(
                'COALESCE(machine_timer_fires.last_fired_at, machine_current_states.state_entered_at) <= ?',
                [now()->subSeconds($timer->delaySeconds)]
            )
            ->limit($batchSize)
            ->get();

        if ($instances->isEmpty()) {
            return;
        }

        $this->dispatchTimerJobs($machineClass, $timer->eventName, $instances);

        // Update fire tracking
        foreach ($instances as $instance) {
            $existing = MachineTimerFire::where('root_event_id', $instance->root_event_id)
                ->where('timer_key', $timer->key())
                ->first();

            if ($existing instanceof MachineTimerFire) {
                $existing->update([
                    'last_fired_at' => now(),
                    'fire_count'    => $existing->fire_count + 1,
                    'status'        => MachineTimerFire::STATUS_ACTIVE,
                ]);
            } else {
                MachineTimerFire::create([
                    'root_event_id' => $instance->root_event_id,
                    'timer_key'     => $timer->key(),
                    'last_fired_at' => now(),
                    'fire_count'    => 1,
                    'status'        => MachineTimerFire::STATUS_ACTIVE,
                ]);
            }
        }
    }

    /**
     * Process max/then: send `then` event for instances that reached max fires.
     */
    protected function processEveryMaxThen(string $machineClass, TimerDefinition $timer, int $batchSize): void
    {
        if ($timer->then === null) {
            return;
        }

        $instances = DB::table('machine_current_states')
            ->select('machine_current_states.*')
            ->where('machine_current_states.machine_class', $machineClass)
            ->where('machine_current_states.state_id', $timer->stateId)
            ->join('machine_timer_fires', function (JoinClause $join) use ($timer): void {
                $join->on('machine_timer_fires.root_event_id', '=', 'machine_current_states.root_event_id')
                    ->where('machine_timer_fires.timer_key', '=', $timer->key());
            })
            ->where('machine_timer_fires.status', MachineTimerFire::STATUS_ACTIVE)
            ->where('machine_timer_fires.fire_count', '>=', $timer->max)
            ->limit($batchSize)
            ->get();

        if ($instances->isEmpty()) {
            return;
        }

        // Send then event
        $this->dispatchTimerJobs($machineClass, $timer->then, $instances);

        // Mark exhausted (prevents re-sending then)
        MachineTimerFire::where('timer_key', $timer->key())
            ->whereIn('root_event_id', $instances->pluck('root_event_id'))
            ->update([
                'status'        => MachineTimerFire::STATUS_EXHAUSTED,
                'last_fired_at' => now(),
            ]);
    }

    /**
     * Dispatch SendToMachineJob for a collection of instances via Bus::batch.
     */
    protected function dispatchTimerJobs(string $machineClass, string $eventName, Collection $instances): void
    {
        $jobs = $instances->map(fn (object $instance): SendToMachineJob => new SendToMachineJob(
            machineClass: $machineClass,
            rootEventId: $instance->root_event_id,
            event: ['type' => $eventName],
        ));

        Bus::batch($jobs->all())
            ->name("timer:{$machineClass}:{$eventName}")
            ->allowFailures()
            ->dispatch();
    }
}
