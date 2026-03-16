<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;

/**
 * Display timer status for machine instances.
 *
 * Shows which instances are in states with timer-configured transitions,
 * and their timer fire history.
 */
class TimerStatusCommand extends Command
{
    protected $signature = 'machine:timer-status
        {--class= : Filter by machine class}
        {--state= : Filter by state ID}
        {--limit=20 : Maximum rows to show}';
    protected $description = 'Show timer status for machine instances';

    public function handle(): int
    {
        $query = DB::table('machine_current_states')
            ->select([
                'machine_current_states.root_event_id',
                'machine_current_states.machine_class',
                'machine_current_states.state_id',
                'machine_current_states.state_entered_at',
                'machine_timer_fires.timer_key',
                'machine_timer_fires.last_fired_at',
                'machine_timer_fires.fire_count',
                'machine_timer_fires.status as timer_status',
            ])
            ->leftJoin('machine_timer_fires', 'machine_timer_fires.root_event_id', '=', 'machine_current_states.root_event_id');

        if ($this->option('class')) {
            $query->where('machine_current_states.machine_class', $this->option('class'));
        }

        if ($this->option('state')) {
            $query->where('machine_current_states.state_id', $this->option('state'));
        }

        $results = $query->limit((int) $this->option('limit'))->get();

        if ($results->isEmpty()) {
            $this->info('No active machine instances found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Root Event ID', 'Machine Class', 'State', 'Entered At', 'Timer Key', 'Last Fired', 'Fire Count', 'Status'],
            $results->map(fn (object $row): array => [
                $row->root_event_id,
                class_basename($row->machine_class),
                $row->state_id,
                $row->state_entered_at,
                $row->timer_key ?? '-',
                $row->last_fired_at ?? '-',
                $row->fire_count ?? '-',
                $row->timer_status ?? '-',
            ])->all()
        );

        // Summary
        $totalInstances = MachineCurrentState::count();
        $this->newLine();
        $this->info("Total tracked instances: {$totalInstances}");

        return self::SUCCESS;
    }
}
