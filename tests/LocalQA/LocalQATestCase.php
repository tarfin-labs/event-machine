<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\LocalQA;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Tarfinlabs\EventMachine\MachineServiceProvider;

/**
 * Base test case for LocalQA tests.
 *
 * Uses REAL MySQL + Redis (not SQLite/sync).
 * Requires: MySQL running (root, no password), Redis running, Horizon running.
 */
class LocalQATestCase extends Orchestra
{
    protected function defineEnvironment($app): void
    {
        // MySQL connection
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql', [
            'driver'    => 'mysql',
            'host'      => env('QA_DB_HOST', '127.0.0.1'),
            'port'      => env('QA_DB_PORT', '3306'),
            'database'  => env('QA_DB_DATABASE', 'qa_event_machine_v7'),
            'username'  => env('QA_DB_USERNAME', 'root'),
            'password'  => env('QA_DB_PASSWORD', ''),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
        ]);

        // Redis queue + cache
        $app['config']->set('queue.default', 'redis');
        $app['config']->set('cache.default', 'redis');

        // Bus::batch config
        $app['config']->set('queue.batching.database', 'mysql');
        $app['config']->set('queue.batching.table', 'job_batches');
    }

    protected function getPackageProviders($app): array
    {
        return [
            MachineServiceProvider::class,
            LaravelDataServiceProvider::class,
        ];
    }

    /**
     * Guarantee a clean slate between tests.
     *
     * Strategy: Drain ALL Redis queues, then truncate all tables.
     * Orphan jobs (from previous test) that are mid-execution will fail
     * with RestoringStateException when they try to read truncated data —
     * this is harmless because the new test uses a different root_event_id.
     *
     * No quiet-period wait needed: each test is isolated by root_event_id.
     */
    public static function cleanTables(): void
    {
        // 1. Drain ALL Redis queues (pending, delayed, reserved, notify)
        $redis  = app('redis');
        $prefix = config('database.redis.options.prefix', 'laravel_database_');

        foreach (['default', 'child-queue'] as $queue) {
            $redis->del("{$prefix}queues:{$queue}");
            $redis->del("{$prefix}queues:{$queue}:delayed");
            $redis->del("{$prefix}queues:{$queue}:reserved");
            $redis->del("{$prefix}queues:{$queue}:notify");
        }

        // 2. Truncate all machine tables
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('machine_events')->truncate();
        DB::table('machine_current_states')->truncate();
        DB::table('machine_timer_fires')->truncate();
        DB::table('machine_children')->truncate();
        DB::table('machine_locks')->truncate();
        DB::table('job_batches')->truncate();

        if (DB::getSchemaBuilder()->hasTable('jobs')) {
            DB::table('jobs')->truncate();
        }

        if (DB::getSchemaBuilder()->hasTable('failed_jobs')) {
            DB::table('failed_jobs')->truncate();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }

    /**
     * Poll DB until condition is true or timeout.
     *
     * Uses exponential backoff (100ms → 1s cap) to reduce DB pressure.
     * On timeout, dumps diagnostics to STDERR for debugging.
     */
    public static function waitFor(
        callable $condition,
        int $timeoutSeconds = 60,
        string $description = '',
    ): bool {
        $start      = time();
        $intervalMs = 100;

        while (time() - $start < $timeoutSeconds) {
            if ($condition()) {
                return true;
            }

            usleep($intervalMs * 1000);
            $intervalMs = min((int) ($intervalMs * 1.5), 1000);
        }

        static::dumpDiagnostics($description);

        return false;
    }

    /**
     * Dump diagnostic state to STDERR on waitFor timeout.
     */
    private static function dumpDiagnostics(string $description): void
    {
        $redis  = app('redis');
        $prefix = config('database.redis.options.prefix', 'laravel_database_');

        $diagnostics = [
            'description'    => $description ?: '(no description)',
            'machine_events' => DB::table('machine_events')->count(),
            'last_5_events'  => DB::table('machine_events')
                ->orderByDesc('sequence_number')
                ->limit(5)
                ->pluck('type')
                ->toArray(),
            'current_states' => DB::table('machine_current_states')
                ->pluck('state_id')
                ->toArray(),
            'children' => DB::table('machine_children')
                ->get(['status', 'child_machine_class'])
                ->toArray(),
            'locks'       => DB::table('machine_locks')->count(),
            'failed_jobs' => DB::table('failed_jobs')
                ->limit(3)
                ->pluck('exception')
                ->map(fn ($e) => mb_substr((string) $e, 0, 200))
                ->toArray(),
        ];

        foreach (['default', 'child-queue'] as $queue) {
            $diagnostics["queue:{$queue}:pending"]  = (int) $redis->llen("{$prefix}queues:{$queue}");
            $diagnostics["queue:{$queue}:delayed"]  = (int) $redis->zcard("{$prefix}queues:{$queue}:delayed");
            $diagnostics["queue:{$queue}:reserved"] = (int) $redis->zcard("{$prefix}queues:{$queue}:reserved");
        }

        fwrite(STDERR, "\n[waitFor TIMEOUT] ".json_encode($diagnostics, JSON_PRETTY_PRINT)."\n");
    }
}
