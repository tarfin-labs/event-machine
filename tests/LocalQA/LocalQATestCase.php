<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\LocalQA;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as Orchestra;
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
        ];
    }

    /**
     * Truncate all machine-related tables and drain Redis queues.
     * Used instead of RefreshDatabase so queue workers can see data.
     *
     * Also drains Redis queues to prevent leftover jobs from previous tests
     * from interfering with the current test's machines.
     */
    public static function cleanTables(): void
    {
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

        // Drain Redis queues — delete queue lists but keep Horizon metadata intact.
        // This prevents leftover jobs from previous tests from processing against
        // new test data (different root_event_ids).
        $redis  = app('redis');
        $prefix = config('database.redis.options.prefix', 'laravel_database_');

        foreach (['default', 'child-queue'] as $queue) {
            $redis->del("{$prefix}queues:{$queue}");
            $redis->del("{$prefix}queues:{$queue}:delayed");
            $redis->del("{$prefix}queues:{$queue}:reserved");
            $redis->del("{$prefix}queues:{$queue}:notify");
        }
    }

    /**
     * Poll DB until condition is true or timeout.
     */
    public static function waitFor(callable $condition, int $timeoutSeconds = 30, int $intervalMs = 250): bool
    {
        $start = time();
        while (time() - $start < $timeoutSeconds) {
            if ($condition()) {
                return true;
            }
            usleep($intervalMs * 1000);
        }

        return false;
    }
}
