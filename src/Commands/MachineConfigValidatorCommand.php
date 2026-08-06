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
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Enums\BehaviorType;
use Tarfinlabs\EventMachine\StateConfigValidator;
use Tarfinlabs\EventMachine\Support\WiringInspector;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\MachineDiscoveryException;

class MachineConfigValidatorCommand extends Command
{
    protected $signature   = 'machine:validate {machine?*} {--all : Validate all machines in the project}';
    protected $description = 'Validate machine configuration for potential issues';
    private readonly Parser $parser;
    private readonly NodeTraverser $traverser;
    private readonly MachineClassVisitor $visitor;

    public function __construct()
    {
        parent::__construct();

        $this->parser    = (new ParserFactory())->createForVersion(PhpVersion::getHostVersion());
        $this->traverser = new NodeTraverser();
        $this->visitor   = new MachineClassVisitor();
        $this->traverser->addVisitor($this->visitor);
    }

    public function handle(): int
    {
        if ($this->option(key: 'all')) {
            return $this->validateAllMachines() ? self::SUCCESS : self::FAILURE;
        }

        $machines = $this->argument(key: 'machine');
        if ($machines === []) {
            $this->error(string: 'Please provide a machine class name or use --all option.');

            return self::INVALID;
        }

        $passed = true;

        foreach ($machines as $machine) {
            // Every named machine is validated; one failure never short-circuits the rest.
            if (!$this->validateMachine($machine)) {
                $passed = false;
            }
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return bool True when the machine validated cleanly.
     */
    protected function validateMachine(string $machineClass): bool
    {
        $fullClassName = $this->resolveNamedMachine($machineClass);

        if ($fullClassName === null) {
            $this->error(string: "Machine class '{$machineClass}' not found.");

            return false;
        }

        return $this->validateResolvedMachine($fullClassName);
    }

    /**
     * Resolve a named machine, preferring the class over the discovered set.
     *
     * Discovery recognises only classes that directly extend Machine, so a machine
     * behind an intermediate base class is invisible to it. Resolving the argument
     * as a class first means naming such a machine validates it instead of failing
     * the run, without widening discovery itself.
     *
     * @return class-string<Machine>|null
     */
    protected function resolveNamedMachine(string $machineClass): ?string
    {
        if (class_exists($machineClass) && is_subclass_of($machineClass, Machine::class)) {
            /* @var class-string<Machine> $machineClass */
            return $machineClass;
        }

        try {
            /** @var class-string<Machine>|null $resolved */
            $resolved = $this->resolveFullClassName($machineClass, $this->findMachineClasses());

            return $resolved;
        } catch (Throwable $e) {
            $this->error(string: "Error validating '{$machineClass}': ".$e->getMessage());

            return null;
        }
    }

    /**
     * Validate one already-resolved machine class.
     *
     * Both entry points funnel through here, so a named invocation and `--all`
     * cannot drift into running different checks or reporting a machine differently.
     *
     * @param  class-string<Machine>  $machineClass
     *
     * @return bool True when the machine produced no findings.
     */
    protected function validateResolvedMachine(string $machineClass): bool
    {
        try {
            $definition = $machineClass::definition();

            if ($definition === null) {
                $this->error(string: "✗ Machine '{$machineClass}' has no definition.");

                return false;
            }

            StateConfigValidator::validate($definition->config);
            $findings = $this->wiringFindings($definition, $machineClass);
        } catch (Throwable $e) {
            $this->error(string: "✗ Error in '{$machineClass}': ".$e->getMessage());

            return false;
        }

        if ($findings === []) {
            $this->info(string: "✓ Machine '{$machineClass}' configuration is valid.");

            return true;
        }

        $this->error(string: "✗ Machine '{$machineClass}' has ".count($findings).' wiring problem(s):');

        foreach ($findings as $finding) {
            $this->line(string: '  '.$finding);
        }

        return false;
    }

    /**
     * The wiring findings for one machine, in a stable order.
     *
     * @param  class-string<Machine>  $machineClass
     *
     * @return list<string>
     */
    protected function wiringFindings(MachineDefinition $definition, string $machineClass): array
    {
        $contextClass = $this->declaredContextClass($definition);

        $findings = [];

        foreach ($definition->referencedBehaviors() as $behaviors) {
            foreach ($behaviors as $behavior) {
                $expected = WiringInspector::incompatibleContextTypes($behavior, $contextClass);

                if ($expected !== null) {
                    $findings[] = "{$behavior}::__invoke() expects ".implode('|', $expected).
                        " but machine {$machineClass} declares context {$contextClass}.";
                }

                foreach (WiringInspector::unsatisfiableRequiredContextKeys($behavior, $contextClass) as $key) {
                    $findings[] = "{$behavior}::\$requiredContext['{$key}'] is not a property of {$contextClass} (machine {$machineClass}).";
                }
            }
        }

        /** @var array<string, class-string<EventBehavior>> $registry */
        $registry = $definition->behavior[BehaviorType::Event->value] ?? [];

        foreach (WiringInspector::eventTypeCollisions($definition->referencedEventClasses(), $registry) as $collision) {
            $owner = $collision['owner'] === null
                ? 'no class currently owns it in the event registry'
                : "the type is currently owned by {$collision['owner']}";

            $findings[] = "Event type '{$collision['type']}' is derived by both ".
                implode(' and ', $collision['classes']).
                ". EventBehavior::getType() takes the class basename before its last 'Event', so classes with different names can produce the same type — {$owner}.";
        }

        return $findings;
    }

    /**
     * The context class a machine declares, or the base ContextManager when it declares none.
     *
     * A typed context is moved into the behavior map during construction and is a class
     * string there; a machine without one leaves the slot holding an empty array, not
     * null, so a `?? null` check would read the wrong thing.
     *
     * @return class-string<ContextManager>
     */
    protected function declaredContextClass(MachineDefinition $definition): string
    {
        $declared = $definition->behavior[BehaviorType::Context->value] ?? null;

        if (is_string($declared) && is_subclass_of($declared, ContextManager::class)) {
            return $declared;
        }

        return ContextManager::class;
    }

    /**
     * @return array<int, string>
     */
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

    /**
     * @return array<int, string>
     */
    protected function getSearchPaths(): array
    {
        $paths = $this->isInPackageDevelopment()
            ? $this->getPackageDevelopmentPaths()
            : $this->getProjectPaths();

        if ($paths === []) {
            throw MachineDiscoveryException::noSearchPaths();
        }

        return array_filter($paths, callback: is_dir(...));
    }

    protected function isInPackageDevelopment(): bool
    {
        return !str_contains($this->getPackageRootPath(), '/vendor/');
    }

    /**
     * @return array<int, string>
     */
    protected function getPackageDevelopmentPaths(): array
    {
        $paths        = [];
        $composerJson = $this->getComposerConfig();

        if ($composerJson === null) {
            return $paths;
        }

        // Add PSR-4 autoload paths
        foreach (['autoload', 'autoload-dev'] as $autoloadType) {
            if (!isset($composerJson[$autoloadType]['psr-4'])) {
                continue;
            }

            foreach ($composerJson[$autoloadType]['psr-4'] as $path) {
                $namespacePaths = (array) $path;
                foreach ($namespacePaths as $namespacePath) {
                    $absolutePath = $this->getPackageRootPath().'/'.trim((string) $namespacePath, characters: '/');
                    if (is_dir($absolutePath)) {
                        $paths[] = $absolutePath;
                    }
                }
            }
        }

        return $paths;
    }

    /**
     * @return array<int, string>
     */
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
    /**
     * @return array<string, mixed>|null
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

        $config = json_decode($content, associative: true);
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

    /**
     * @param  array<int, string>  $machines
     */
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

    protected function validateAllMachines(): bool
    {
        try {
            $machines = $this->findMachineClasses();
        } catch (Throwable $e) {
            // Discovery itself failed, so no sweep happened. Reporting this the same
            // way as a completed run would let a broken search path read as clean.
            $this->error(string: 'Machine discovery failed: '.$e->getMessage());

            return false;
        }

        if ($machines === []) {
            $this->error(string: 'No machines discovered in: '.implode(', ', $this->getSearchPaths()));

            return false;
        }

        // Informational only — a shrinking count never fails on its own, so it cannot
        // serve as a discovery-regression signal. Name machines explicitly for that.
        $this->info(string: 'Discovered '.count($machines).' machine(s)');

        $validated = 0;
        $failed    = 0;

        foreach ($machines as $class) {
            // One machine failing never stops the sweep: the remaining machines are
            // still validated so a single break does not hide every other finding.
            if ($this->validateResolvedMachine($class)) {
                $validated++;

                continue;
            }

            $failed++;
        }

        $this->newLine();
        $this->info(string: "Validation complete: {$validated} valid, {$failed} failed");

        return $failed === 0;
    }
}
