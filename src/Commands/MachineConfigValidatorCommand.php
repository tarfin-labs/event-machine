<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Throwable;
use ReflectionClass;
use PhpParser\Parser;
use RuntimeException;
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
        $paths = $this->isInPackageDevelopment()
            ? $this->getPackageDevelopmentPaths()
            : $this->getProjectPaths();

        if (empty($paths)) {
            throw new RuntimeException(
                message: 'No valid search paths found for Machine classes. '.
                'If you are using event-machine package, please ensure your Machine classes are in the app/ directory.'
            );
        }

        return array_filter($paths, callback: 'is_dir');
    }

    protected function isInPackageDevelopment(): bool
    {
        return !str_contains($this->getPackageRootPath(), '/vendor/');
    }

    protected function getPackageDevelopmentPaths(): array
    {
        $paths        = [];
        $composerJson = $this->getComposerConfig();

        if (!$composerJson) {
            return $paths;
        }

        // Add PSR-4 autoload paths
        foreach (['autoload', 'autoload-dev'] as $autoloadType) {
            if (!isset($composerJson[$autoloadType]['psr-4'])) {
                continue;
            }

            foreach ($composerJson[$autoloadType]['psr-4'] as $namespace => $path) {
                $namespacePaths = (array) $path;
                foreach ($namespacePaths as $namespacePath) {
                    $absolutePath = $this->getPackageRootPath().'/'.trim($namespacePath, characters: '/');
                    if (is_dir($absolutePath)) {
                        $paths[] = $absolutePath;
                    }
                }
            }
        }

        return $paths;
    }

    protected function getProjectPaths(): array
    {
        $paths = [];

        // Project app directory
        $appPath = base_path('app');
        if (is_dir($appPath)) {
            $paths[] = $appPath;
        }

        return $paths;
    }

    /**
     * @throws \JsonException
     */
    protected function getComposerConfig(): ?array
    {
        $composerPath = $this->getPackageRootPath().'/composer.json';

        if (!file_exists($composerPath)) {
            return null;
        }

        $content = file_get_contents($composerPath);
        if ($content === false) {
            return null;
        }

        $config = json_decode($content, associative: true, depth: JSON_THROW_ON_ERROR, flags: JSON_THROW_ON_ERROR);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $config;
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
