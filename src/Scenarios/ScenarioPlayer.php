<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

use Illuminate\Support\Facades\App;
use Tarfinlabs\EventMachine\Actor\Machine;
use Illuminate\Database\Eloquent\Factories\Factory;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Exceptions\ScenarioFailedException;
use Tarfinlabs\EventMachine\Exceptions\ScenariosDisabledException;
use Tarfinlabs\EventMachine\Exceptions\ScenarioConfigurationException;

class ScenarioPlayer
{
    /** @var array<string, mixed> Original container bindings to restore during cleanup */
    private array $originalBindings = [];

    /**
     * Play a scenario with optional parameter overrides.
     */
    public function play(MachineScenario $scenario, array $params = []): ScenarioResult
    {
        $startTime = microtime(true);

        // 1. Validate environment
        $this->validateEnvironment();

        // 2. Resolve parent chain
        $parentResult = null;
        if ($scenario->getParent() !== null) {
            $parentResult = $this->resolveParentChain($scenario, $params);
        }

        // 3. Hydrate scenario
        $parentModels = $parentResult?->models ?? [];
        $scenario->hydrate($params, $parentModels);

        // 4. Create models
        $this->createModels($scenario);

        // 5. Register stubs
        $this->registerStubs($scenario);

        // 6. Replay steps
        $machine       = null;
        $stepsExecuted = 0;
        $childResults  = [];

        try {
            $steps = $scenario->getSteps();

            foreach ($steps as $index => $step) {
                if ($step instanceof ChildScenarioStep) {
                    // Child scenario execution will be implemented in composition phase
                    continue;
                }

                if ($step instanceof ScenarioStep) {
                    if ($machine === null) {
                        // First send — create the machine
                        /** @var class-string<Machine> $machineClass */
                        $machineClass = $scenario->getMachine();

                        if ($parentResult instanceof ScenarioResult) {
                            // Continue from parent's machine
                            $machine = Machine::restoreFromRootEventId(
                                machineDefinitionClass: $machineClass,
                                rootEventId: $parentResult->rootEventId,
                            );
                        }
                    }

                    try {
                        if ($machine === null) {
                            /** @var class-string<Machine> $machineClass */
                            $machineClass = $scenario->getMachine();
                            $machine      = $machineClass::create([
                                'type'    => $step->eventType,
                                'payload' => $step->payload,
                            ]);
                        } else {
                            $machine->send([
                                'type'    => $step->eventType,
                                'payload' => $step->payload,
                            ]);
                        }

                        $stepsExecuted++;
                    } catch (\Throwable $e) {
                        throw new ScenarioFailedException(
                            stepIndex: $index,
                            eventType: $step->eventType,
                            currentState: $machine?->state?->currentStateDefinition?->key ?? 'unknown',
                            rejectionReason: $e->getMessage(),
                        );
                    }
                }
            }
        } finally {
            // 7. Cleanup stubs
            $this->cleanupStubs();
        }

        // 8. Build result
        $duration = (microtime(true) - $startTime) * 1000;

        return new ScenarioResult(
            machineId: $machine?->state?->history?->first()?->root_event_id ?? '',
            rootEventId: $machine?->state?->history?->first()?->root_event_id ?? '',
            currentState: $machine?->state?->currentStateDefinition?->key ?? '',
            models: $parentModels,
            stepsExecuted: $stepsExecuted,
            duration: $duration,
            childResults: $childResults,
        );
    }

    /**
     * Validate that scenarios are enabled in the environment.
     */
    private function validateEnvironment(): void
    {
        if (!config('machine.scenarios.enabled', false)) {
            throw new ScenariosDisabledException();
        }
    }

    /**
     * Resolve and play the parent scenario chain.
     */
    private function resolveParentChain(MachineScenario $scenario, array $params): ScenarioResult
    {
        $parentClass = $scenario->getParent();
        /** @var MachineScenario $parentScenario */
        $parentScenario = new $parentClass();

        // Validate machine match
        if ($parentScenario->getMachine() !== $scenario->getMachine()) {
            throw ScenarioConfigurationException::machineMismatch(
                parentScenario: $parentClass,
                parentMachine: $parentScenario->getMachine(),
                childScenario: $scenario::class,
                childMachine: $scenario->getMachine(),
            );
        }

        return $this->play($parentScenario, $params);
    }

    /**
     * Create Eloquent models defined in the scenario.
     */
    private function createModels(MachineScenario $scenario): void
    {
        $modelDefinitions = $scenario->getModels();

        foreach ($modelDefinitions as $name => $factory) {
            $model = $factory instanceof Factory
                ? $factory->create()
                : $factory();

            $scenario->addModel($name, $model);
        }
    }

    /**
     * Register stubs from arrange() into the service container.
     */
    private function registerStubs(MachineScenario $scenario): void
    {
        $arrange = $scenario->getArrange();

        foreach ($arrange as $class => $value) {
            if (is_subclass_of($class, GuardBehavior::class)) {
                $this->registerGuardStub($class, $value);
            } elseif (is_subclass_of($class, ActionBehavior::class)) {
                $this->registerActionStub($class, $value);
            } else {
                $this->registerServiceStub($class, $value);
            }
        }
    }

    /**
     * Register a guard stub that returns a predetermined boolean.
     */
    private function registerGuardStub(string $guardClass, bool $returnValue): void
    {
        $this->originalBindings[$guardClass] = App::bound($guardClass);

        App::bind($guardClass, fn ($app, $params): ScenarioGuardStub => new ScenarioGuardStub(
            returnValue: $returnValue,
            eventQueue: $params['eventQueue'] ?? null,
        ));
    }

    /**
     * Register an action stub that applies predetermined data.
     */
    private function registerActionStub(string $actionClass, array $stubData): void
    {
        $this->originalBindings[$actionClass] = App::bound($actionClass);

        App::bind($actionClass, fn ($app, $params): ScenarioActionStub => new ScenarioActionStub(
            originalClass: $actionClass,
            stubData: $stubData,
            eventQueue: $params['eventQueue'] ?? null,
        ));
    }

    /**
     * Register a service stub with predetermined method return values.
     */
    private function registerServiceStub(string $serviceClass, array $methodMap): void
    {
        $this->originalBindings[$serviceClass] = App::bound($serviceClass);

        // Create anonymous class stub that overrides specified methods
        $stub = new class($methodMap) {
            public function __construct(private readonly array $returns) {}

            public function __call(string $method, array $args): mixed
            {
                if (!isset($this->returns[$method])) {
                    throw new \RuntimeException("No stub defined for method {$method}");
                }

                return $this->returns[$method];
            }
        };

        App::instance($serviceClass, $stub);
    }

    /**
     * Remove stubs from the service container.
     */
    private function cleanupStubs(): void
    {
        foreach ($this->originalBindings as $class => $wasBound) {
            if (!$wasBound) {
                app()->offsetUnset($class);
            }
        }

        $this->originalBindings = [];
    }
}
