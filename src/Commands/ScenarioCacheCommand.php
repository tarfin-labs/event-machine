<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Tarfinlabs\EventMachine\Scenarios\ScenarioDiscovery;

class ScenarioCacheCommand extends Command
{
    protected $signature   = 'machine:scenario-cache';
    protected $description = 'Cache scenario class discovery for production-like staging environments';

    public function handle(): int
    {
        $scenarios = ScenarioDiscovery::discover();
        $cachePath = app()->bootstrapPath('cache/machine-scenarios.php');

        File::put($cachePath, '<?php return '.var_export($scenarios, true).';');

        $this->info('Scenario classes cached successfully. Found '.count($scenarios).' scenario(s).');

        return self::SUCCESS;
    }
}
