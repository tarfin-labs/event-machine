<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Illuminate\Console\Command;
use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Scenarios\ScenarioDiscovery;

class ScenarioCommand extends Command
{
    protected $signature = 'machine:scenario
        {scenario? : The scenario class name to play}
        {--list : List all available scenarios}
        {--machine= : Filter scenarios by machine class (used with --list)}
        {--param=* : Parameter overrides in key:value format}';
    protected $description = 'Play or list machine scenarios';

    public function handle(): int
    {
        if (!config('machine.scenarios.enabled', false)) {
            $this->error('Scenarios are disabled. Set MACHINE_SCENARIOS_ENABLED=true to enable.');

            return self::FAILURE;
        }

        if ($this->option('list')) {
            return $this->listScenarios();
        }

        $scenarioName = $this->argument('scenario');
        if ($scenarioName === null) {
            $this->error('Please provide a scenario class name or use --list to see available scenarios.');

            return self::FAILURE;
        }

        return $this->playScenario($scenarioName);
    }

    private function listScenarios(): int
    {
        $scenarios     = ScenarioDiscovery::discover();
        $machineFilter = $this->option('machine');

        $grouped = [];
        foreach ($scenarios as $scenarioClass) {
            /** @var MachineScenario $instance */
            $instance = new $scenarioClass();
            $machine  = class_basename($instance->getMachine());

            if ($machineFilter !== null && $machine !== $machineFilter) {
                continue;
            }

            $grouped[$machine][] = [
                'class'       => class_basename($scenarioClass),
                'description' => $instance->getDescription(),
                'parent'      => $instance->getParent() ? class_basename($instance->getParent()) : null,
            ];
        }

        if ($grouped === []) {
            $this->info('No scenarios found.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line(' <fg=cyan;options=bold>Machine Scenarios</>');
        $this->line(' '.str_repeat('═', 17));

        foreach ($grouped as $machine => $items) {
            $this->newLine();
            $this->line(" <fg=white;options=bold>{$machine}</>");
            $this->line(' '.str_repeat('─', mb_strlen($machine)));

            foreach ($items as $item) {
                $parentSuffix = $item['parent'] ? "  <fg=gray>(parent: {$item['parent']})</>" : '';
                $this->line("  <fg=green>{$item['class']}</>  {$item['description']}{$parentSuffix}");
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function playScenario(string $scenarioName): int
    {
        $scenarioClass = $this->resolveScenarioClass($scenarioName);

        if ($scenarioClass === null) {
            $this->error("Scenario class '{$scenarioName}' not found.");

            return self::FAILURE;
        }

        $params = $this->parseParams();

        /** @var MachineScenario $instance */
        $instance = new $scenarioClass();

        $this->newLine();
        $this->line(" ┌─ Scenario: <fg=cyan;options=bold>{$scenarioName}</> ─");
        $this->line(' │ Machine:     '.class_basename($instance->getMachine()));
        $this->line(" │ Description: {$instance->getDescription()}");
        $this->line(' │ Parent:      '.($instance->getParent() ? class_basename($instance->getParent()) : '—'));

        if ($params !== []) {
            $paramStr = collect($params)->map(fn ($v, $k): string => "{$k}={$v}")->implode(', ');
            $this->line(" │ Parameters:  {$paramStr}");
        }

        $this->line(' ├'.str_repeat('─', 60));
        $this->line(' │ Playing scenario...');

        try {
            $result = $scenarioClass::play($params);

            $this->line(' ├'.str_repeat('─', 60));
            $this->line(" │ <fg=green>✓</> Done! Machine is now at: <fg=cyan;options=bold>{$result->currentState}</>");
            $this->line(" │   Machine ID: {$result->machineId}");
            $this->line(" │   Root Event: {$result->rootEventId}");
            $this->line(' │   Duration:   '.round($result->duration, 1).'ms');
            $this->line(' └'.str_repeat('─', 60));
            $this->newLine();

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->line(' ├'.str_repeat('─', 60));
            $this->error(" │ ✗ Failed: {$e->getMessage()}");
            $this->line(' └'.str_repeat('─', 60));
            $this->newLine();

            return self::FAILURE;
        }
    }

    private function parseParams(): array
    {
        $params = [];

        foreach ($this->option('param') as $param) {
            $parts = explode(':', (string) $param, 2);
            if (count($parts) !== 2) {
                $this->warn("Skipping malformed parameter: {$param} (expected key:value format)");

                continue;
            }
            [$key, $value] = $parts;
            $params[$key]  = $value;
        }

        return $params;
    }

    private function resolveScenarioClass(string $name): ?string
    {
        // Try as FQCN first
        if (class_exists($name) && is_subclass_of($name, MachineScenario::class)) {
            return $name;
        }

        // Search in discovered scenarios
        foreach (ScenarioDiscovery::discover() as $class) {
            if (class_basename($class) === $name) {
                return $class;
            }
        }

        return null;
    }
}
