<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Illuminate\Console\Command;

/**
 * Clear the machine cache file.
 *
 * Falls back to runtime discovery when cache is cleared.
 */
class MachineClearCommand extends Command
{
    protected $signature   = 'machine:clear';
    protected $description = 'Clear the machine class cache (fall back to runtime discovery)';

    public function handle(): int
    {
        $cachePath = $this->laravel->bootstrapPath('cache/machines.php');

        if (file_exists($cachePath)) {
            unlink($cachePath);
            $this->info('Machine cache cleared.');
        } else {
            $this->info('Machine cache not found (already cleared).');
        }

        return self::SUCCESS;
    }
}
