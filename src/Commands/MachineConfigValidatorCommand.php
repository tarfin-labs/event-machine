<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Throwable;
use ReflectionClass;
use PhpParser\Parser;
use PhpParser\PhpVersion;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\StateConfigValidator;

class MachineConfigValidatorCommand extends Command
{
    protected $signature   = 'machine:validate {machine?*} {--all : Validate all machines in the project}';
    protected $description = 'Validate machine configuration for potential issues';
    private Parser $parser;
    private NodeTraverser $traverser;
    private MachineClassVisitor $visitor;

    public function __construct()
    {
        parent::__construct();

        $this->parser    = (new ParserFactory())->createForVersion(PhpVersion::getHostVersion());
        $this->traverser = new NodeTraverser();
        $this->visitor   = new MachineClassVisitor();
        $this->traverser->addVisitor($this->visitor);
    }

    public function handle(): void
    {
        if ($this->option(key: 'all')) {
            $this->validateAllMachines();

            return;
        }

        $machines = $this->argument(key: 'machine');
        if (empty($machines)) {
            $this->error(string: 'Please provide a machine class name or use --all option.');

            return;
        }

        foreach ($machines as $machine) {
            $this->validateMachine($machine);
        }
    }

    protected function validateMachine(string $machineClass): void
    {
        try {
            $machines = $this->findMachineClasses();
            /** @var Machine $fullClassName */
            $fullClassName = $this->resolveFullClassName($machineClass, $machines);

            if (!$fullClassName) {
                $this->error(string: "Machine class '{$machineClass}' not found.");

                return;
            }

            $definition = $fullClassName::definition();
            if ($definition === null) {
                $this->error(string: "Machine '{$fullClassName}' has no definition.");

                return;
            }

            StateConfigValidator::validate($definition->config);
            $this->info(string: "✓ Machine '{$fullClassName}' configuration is valid.");

        } catch (Throwable $e) {
            $this->error(string: "Error validating '{$machineClass}': ".$e->getMessage());
        }
    }

    protected function findMachineClasses(): array
    {
        $searchPaths = $this->getSearchPaths();
        $machines    = [];

        $finder = new Finder();
        $finder->files()
            ->name(patterns: '*.php')
            ->in($searchPaths);

        foreach ($finder as $file) {
            try {
                $code = $file->getContents();
                $ast  = $this->parser->parse($code);

                $this->visitor->setCurrentFile($file->getRealPath());
                $this->traverser->traverse($ast);

                $machines[] = $this->visitor->getMachineClasses();
            } catch (Throwable) {
                continue;
            }
        }

        return array_unique(array_merge(...$machines));
    }

    protected function getSearchPaths(): array
    {
        // Package development environment
        if ($this->isInPackageDevelopment()) {
            return [
                $this->getPackageRootPath().'/src',
                $this->getPackageRootPath().'/tests/Stubs/Machines',
            ];
        }

        // Project environment (where package is installed)
        return [
            base_path(path: 'app'),
        ];
    }

    protected function isInPackageDevelopment(): bool
    {
        return str_contains(
            haystack: $this->getPackageRootPath(),
            needle: 'vendor/orchestra/testbench-core/laravel'
        ) === false;
    }

    protected function getPackageRootPath(): string
    {
        $reflection = new ReflectionClass(objectOrClass: Machine::class);

        return dirname($reflection->getFileName(), levels: 3);
    }

    protected function resolveFullClassName(string $shortName, array $machines): ?string
    {
        // If it's already a full class name
        if (in_array($shortName, $machines, strict: true)) {
            return $shortName;
        }

        // Try to find by class basename
        foreach ($machines as $machine) {
            if (class_basename($machine) === $shortName) {
                return $machine;
            }
        }

        return null;
    }

    protected function validateAllMachines(): void
    {
        $validated = 0;
        $failed    = 0;

        $machines = $this->findMachineClasses();

        foreach ($machines as $class) {
            try {
                $definition = $class::definition();
                if ($definition === null) {
                    $this->warn(string: "Machine '{$class}' has no definition.");
                    $failed++;

                    continue;
                }

                StateConfigValidator::validate($definition->config);
                $this->info(string: "✓ Machine '{$class}' configuration is valid.");
                $validated++;
            } catch (Throwable $e) {
                $this->error(string: "✗ Error in '{$class}': ".$e->getMessage());
                $failed++;
            }
        }

        $this->newLine();
        $this->info(string: "Validation complete: {$validated} valid, {$failed} failed");
    }
}
