<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class DiagramCommand extends Command
{
    protected $signature = 'machine:diagram
        {machine* : One or more Machine class paths}
        {--output= : Output file path (default: auto-generated)}
        {--stdout : Print HTML to stdout instead of writing to file}
        {--open : Open in default browser after generating}';

    protected $description = 'Generate an interactive HTML diagram for EventMachine definitions';

    public function handle(): int
    {
        $machinePaths = $this->argument('machine');
        $machines     = [];

        foreach ($machinePaths as $machinePath) {
            // Resolve file path to FQCN if a file path was given
            if (str_ends_with($machinePath, '.php') || str_contains($machinePath, DIRECTORY_SEPARATOR)) {
                $machinePath = $this->resolveClassFromFile($machinePath);
                if ($machinePath === null) {
                    $this->error("Could not resolve a Machine class from the given file path: {$machinePath}");

                    return self::FAILURE;
                }
            }

            if (!class_exists($machinePath)) {
                $this->error("Machine class not found: {$machinePath}");

                return self::FAILURE;
            }

            // Use the ExportXStateCommand logic to get JSON
            $machine    = $machinePath::create();
            $definition = $machine->definition;

            $xstateCommand = new ExportXStateCommand();
            $xstateJson    = $this->getXStateJson($definition);

            $machines[] = [
                'class' => $machinePath,
                'id'    => $definition->id,
                'json'  => $xstateJson,
            ];
        }

        $html = $this->generateHtml($machines);

        if ($this->option('stdout')) {
            $this->line($html);

            return self::SUCCESS;
        }

        $output = $this->option('output') ?? $this->resolveOutputPath($machines);
        File::put($output, $html);

        $this->info("Diagram generated: {$output}");

        if ($this->option('open')) {
            // macOS
            if (PHP_OS_FAMILY === 'Darwin') {
                exec("open {$output}");
            } elseif (PHP_OS_FAMILY === 'Linux') {
                exec("xdg-open {$output}");
            }
        }

        return self::SUCCESS;
    }

    private function getXStateJson(MachineDefinition $definition): array
    {
        // Re-use the ExportXStateCommand's buildMachineNode logic
        $command = new ExportXStateCommand();

        // Use reflection to call private method
        $reflection = new \ReflectionClass($command);
        $method     = $reflection->getMethod('buildMachineNode');

        $xstate = $method->invoke($command, $definition);

        // Convert empty arrays to objects for proper JSON encoding
        $convertMethod = $reflection->getMethod('convertEmptyArraysToObjects');

        return (array) $convertMethod->invoke($command, $xstate);
    }

    private function generateHtml(array $machines): string
    {
        $templatePath = dirname(__DIR__, 2).'/resources/diagram-template.html';
        $template     = File::get($templatePath);

        // Inject machine data
        $machinesJson = json_encode($machines, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return str_replace('/* __MACHINE_DATA__ */', "const MACHINES = {$machinesJson};", $template);
    }

    private function resolveOutputPath(array $machines): string
    {
        if (count($machines) === 1) {
            $id = $machines[0]['id'];

            return base_path("{$id}-diagram.html");
        }

        return base_path('machine-system-diagram.html');
    }

    private function resolveClassFromFile(string $filePath): ?string
    {
        if (!file_exists($filePath)) {
            $filePath = base_path($filePath);
            if (!file_exists($filePath)) {
                return null;
            }
        }

        $contents  = file_get_contents($filePath);
        $namespace = null;
        $class     = null;

        if (preg_match('/namespace\s+([^;]+);/', $contents, $matches)) {
            $namespace = trim($matches[1]);
        }

        if (preg_match('/class\s+(\w+)\s+extends/', $contents, $matches)) {
            $class = $matches[1];
        }

        if ($class === null) {
            return null;
        }

        $fqcn = $namespace !== null ? $namespace.'\\'.$class : $class;

        if (!class_exists($fqcn)) {
            require_once $filePath;
        }

        return class_exists($fqcn) ? $fqcn : null;
    }
}
