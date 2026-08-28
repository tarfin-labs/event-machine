<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

use ReflectionClass;
use Illuminate\Support\Collection;

/**
 * Discovers MachineScenario classes for a given machine.
 * Scans the Scenarios/ directory relative to the machine class file location.
 */
class ScenarioDiscovery
{
    /** @var array<string, list<class-string<MachineScenario>>> Cache: machineClass → scenario classes */
    private static array $cache = [];

    /**
     * Scenario files found on disk that never became a scenario, and why.
     *
     * Both skips below are correct — one unusable file must not take down discovery for every
     * other scenario — but doing them silently made a broken scenario indistinguishable from
     * one that does not exist. It simply left the list, so the command that validates
     * scenarios reported a smaller total and exited 0. That is the shape this records.
     *
     * @var array<string, list<array{file: string, class: string, reason: string}>>
     */
    private static array $fileSkips = [];

    /** @var array<string, list<array{file: string, class: string, reason: string}>> */
    private static array $constructSkips = [];

    /**
     * Discover all scenarios for a machine class.
     *
     * @return Collection<int, MachineScenario>
     */
    public static function forMachine(string $machineClass): Collection
    {
        $scenarioClasses = self::discoverClasses($machineClass);

        // Rebuilt per call rather than accumulated: forMachine() is not cached, and a list that
        // grew on every call would report the same broken scenario several times.
        self::$constructSkips[$machineClass] = [];

        return collect($scenarioClasses)
            ->map(function (string $class) use ($machineClass): ?MachineScenario {
                try {
                    return new $class();
                } catch (\Throwable $e) {
                    // A scenario that cannot be constructed is skipped so one broken file does
                    // not take down the rest. An earlier revision of this comment claimed the
                    // validate command caught these later; it cannot — that command takes its
                    // list from this method, so a scenario dropped here never reaches it.
                    // Recording the skip is what makes it visible.
                    self::$constructSkips[$machineClass][] = [
                        'file'   => (string) (new ReflectionClass($class))->getFileName(),
                        'class'  => $class,
                        'reason' => 'construction failed — '.$e->getMessage(),
                    ];

                    return null;
                }
            })
            ->filter()
            ->values();
    }

    /**
     * Scenario files that were found but never validated, with the reason for each.
     *
     * @return list<array{file: string, class: string, reason: string}>
     */
    public static function skippedFor(string $machineClass): array
    {
        // Discovery has to have run for the answer to mean anything, and both stages are
        // idempotent, so asking for the skips is enough to produce them.
        self::forMachine($machineClass);

        return array_merge(
            self::$fileSkips[$machineClass] ?? [],
            self::$constructSkips[$machineClass] ?? [],
        );
    }

    /**
     * Find scenarios available at a specific source state + event.
     *
     * @return Collection<int, MachineScenario>
     */
    public static function forStateAndEvent(string $machineClass, string $currentState, ?string $eventType = null): Collection
    {
        return self::forMachine($machineClass)->filter(function (MachineScenario $scenario) use ($currentState, $eventType): bool {
            $sourceMatch = $scenario->source() === $currentState
                || str_ends_with($currentState, '.'.$scenario->source());

            if (!$sourceMatch) {
                return false;
            }

            if ($eventType !== null) {
                return $scenario->eventType() === $eventType;
            }

            return true;
        })->values();
    }

    /**
     * Resolve a scenario by slug for a machine.
     */
    public static function resolveBySlug(string $machineClass, string $slug): ?MachineScenario
    {
        return self::forMachine($machineClass)->first(
            fn (MachineScenario $scenario): bool => $scenario->slug() === $slug
        );
    }

    /**
     * Group available scenarios by event type for endpoint response.
     *
     * @return array<string, list<array{slug: string, description: string, target: string, params: array<string, mixed>}>>
     */
    public static function groupedByEvent(string $machineClass, string $currentState): array
    {
        $scenarios = self::forMachine($machineClass)->filter(function (MachineScenario $scenario) use ($currentState): bool {
            if ($scenario->source() === $currentState) {
                return true;
            }

            return str_ends_with($currentState, '.'.$scenario->source());
        });

        $grouped = [];

        foreach ($scenarios as $scenario) {
            $eventKey = $scenario->eventType();

            $grouped[$eventKey][] = [
                'slug'        => $scenario->slug(),
                'description' => $scenario->description(),
                'target'      => $scenario->target(),
                'params'      => self::serializeParams($scenario),
            ];
        }

        return $grouped;
    }

    /**
     * Reset the discovery cache (for testing).
     */
    public static function resetCache(): void
    {
        self::$cache          = [];
        self::$fileSkips      = [];
        self::$constructSkips = [];
    }

    /**
     * Discover scenario class names from the Scenarios/ directory.
     *
     * @return list<class-string<MachineScenario>>
     */
    private static function discoverClasses(string $machineClass): array
    {
        if (isset(self::$cache[$machineClass])) {
            return self::$cache[$machineClass];
        }

        self::$fileSkips[$machineClass] = [];

        $reflection  = new ReflectionClass($machineClass);
        $machineFile = $reflection->getFileName();

        if ($machineFile === false) {
            self::$cache[$machineClass] = [];

            return [];
        }

        $scenarioDir = dirname($machineFile).'/Scenarios';

        if (!is_dir($scenarioDir)) {
            self::$cache[$machineClass] = [];

            return [];
        }

        $classes = [];

        // Get machine namespace and derive scenario namespace
        $machineNamespace  = $reflection->getNamespaceName();
        $scenarioNamespace = $machineNamespace.'\\Scenarios';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($scenarioDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace($scenarioDir.'/', '', $file->getPathname());
            $className    = $scenarioNamespace.'\\'.str_replace(
                ['/', '.php'],
                ['\\', ''],
                $relativePath,
            );

            if (!class_exists($className)) {
                // The file is there and named like a scenario, but nothing loads under that
                // FQCN — a namespace that does not match the path, or a class the autoloader
                // cannot resolve. Skipping is right; skipping silently is what hid it.
                self::$fileSkips[$machineClass][] = [
                    'file'   => $file->getPathname(),
                    'class'  => $className,
                    'reason' => 'class not found — the file does not declare '.$className,
                ];

                continue;
            }

            $classReflection = new ReflectionClass($className);

            if ($classReflection->isSubclassOf(MachineScenario::class) && !$classReflection->isAbstract()) {
                $classes[] = $className;
            }
        }

        self::$cache[$machineClass] = $classes;

        return $classes;
    }

    /**
     * Serialize params definition for endpoint response.
     * Normalizes both plain and rich formats, auto-derives 'required' flag.
     */
    /**
     * @return array<string, mixed>
     */
    private static function serializeParams(MachineScenario $scenario): array
    {
        $paramDefs  = $scenario->resolvedParams();
        $serialized = [];

        foreach ($paramDefs as $key => $definition) {
            if (is_array($definition) && array_key_exists('rules', $definition)) {
                // Rich definition — pass through with auto-derived required flag
                $serialized[$key] = array_merge($definition, [
                    'required' => self::isRequired($definition['rules'] ?? []),
                ]);
            } else {
                // Plain rules — wrap with required flag
                $serialized[$key] = [
                    'rules'    => $definition,
                    'required' => self::isRequired(is_array($definition) ? $definition : []),
                ];
            }
        }

        return $serialized;
    }

    /**
     * Check if a rules array contains 'required'.
     */
    /**
     * @param  array<int, mixed>  $rules
     */
    private static function isRequired(array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($rule === 'required' || (is_string($rule) && str_starts_with($rule, 'required'))) {
                return true;
            }
        }

        return false;
    }
}
