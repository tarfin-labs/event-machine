<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Illuminate\Console\Command;
use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Scenarios\ScenarioDiscovery;
use Tarfinlabs\EventMachine\Scenarios\ScenarioValidator;

class MachineScenarioValidateCommand extends Command
{
    protected $signature = 'machine:scenario-validate
        {machine? : Validate scenarios for a specific machine (FQCN or class name)}
        {--scenario= : Validate a single scenario by class name or slug}';
    protected $description = 'Validate all scenarios against their machine definitions';

    public function handle(): int
    {
        $machineClass   = $this->argument('machine');
        $scenarioFilter = $this->option('scenario');

        if ($machineClass !== null && !class_exists($machineClass)) {
            $this->error("Machine class not found: {$machineClass}");

            return self::FAILURE;
        }

        if ($machineClass !== null) {
            return $this->validateMachine($machineClass, $scenarioFilter);
        }

        // TODO: discover all machines — for now require machine argument
        $this->error('Please specify a machine class. Auto-discovery of all machines is not yet implemented.');

        return self::FAILURE;
    }

    private function validateMachine(string $machineClass, ?string $scenarioFilter): int
    {
        $scenarios = ScenarioDiscovery::forMachine($machineClass);

        if ($scenarioFilter !== null) {
            $scenarios = $scenarios->filter(function (MachineScenario $s) use ($scenarioFilter): bool {
                if ($s->slug() === $scenarioFilter) {
                    return true;
                }
                if ($s::class === $scenarioFilter) {
                    return true;
                }

                return class_basename($s::class) === $scenarioFilter;
            });
        }

        if ($scenarios->isEmpty()) {
            $this->warn('No scenarios found.');

            return self::SUCCESS;
        }

        $machineShort = class_basename($machineClass);
        $this->line('');
        $this->line("<info>{$machineShort}</info> ({$scenarios->count()} scenarios)");

        $passed = 0;
        $failed = 0;

        foreach ($scenarios as $scenario) {
            $validator = new ScenarioValidator($scenario);

            // Level 1: Static checks
            $errors = $validator->validate();

            // Level 2: Path checks (only if Level 1 passes)
            if ($errors === []) {
                $pathErrors = $validator->validatePaths();
                $errors     = array_merge($errors, $pathErrors);
            }

            $slug   = $scenario->slug();
            $source = $scenario->source();
            $target = $scenario->target();

            if ($errors === []) {
                $this->line("  <fg=green>✓</> {$slug}  <fg=gray>{$source} → {$target}</>");
                $passed++;
            } else {
                $this->line("  <fg=red>✗</> {$slug}  <fg=gray>{$source} → {$target}</>");
                foreach ($errors as $error) {
                    $this->line("    <fg=red>{$error}</>");
                }
                $failed++;
            }
        }

        $this->line('');
        $summary = "{$passed} passed";
        if ($failed > 0) {
            $summary .= ", <fg=red>{$failed} failed</>";
        }
        $this->line($summary);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
