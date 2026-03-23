<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Bus;
use Illuminate\Contracts\Bus\Dispatcher;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Jobs\ChildJobJob;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Jobs\ChildMachineJob;
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

        // 6. Intercept async dispatch & suspend timers
        $originalDispatcher   = resolve(Dispatcher::class);
        $childMachineJobClass = ChildMachineJob::class;
        $childJobJobClass     = ChildJobJob::class;
        $jobsToFake           = array_filter([$childMachineJobClass, $childJobJobClass], class_exists(...));
        if ($jobsToFake !== []) {
            Bus::fake($jobsToFake);
        }
        app()->instance('scenario.timers_disabled', true);

        // 7. Replay steps
        $machine       = null;
        $stepsExecuted = 0;
        $childResults  = [];

        try {
            $steps = $scenario->getSteps();

            foreach ($steps as $index => $step) {
                if ($step instanceof ChildScenarioStep) {
                    $childResult = $this->playChildScenario($step, $machine);
                    if ($childResult instanceof ScenarioResult) {
                        $childResults[$step->machineClass] = $childResult;
                    }
                    $stepsExecuted++;

                    continue;
                }

                if ($step instanceof ScenarioStep) {
                    if ($machine === null) {
                        /** @var class-string<Machine> $machineClass */
                        $machineClass = $scenario->getMachine();

                        if ($parentResult instanceof ScenarioResult) {
                            // Continue from parent's machine
                            $machine = $machineClass::create(state: $parentResult->rootEventId);
                        } else {
                            // Create a new machine instance
                            $machine = $machineClass::create();
                        }
                    }

                    try {
                        $machine->send([
                            'type'    => $step->eventType,
                            'payload' => $step->payload,
                        ]);

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
            // 8. Cleanup stubs, restore dispatcher, remove timer flag
            $this->cleanupStubs();

            if ($jobsToFake !== []) {
                Bus::swap($originalDispatcher);
            }

            app()->forgetInstance('scenario.timers_disabled');
        }

        // 9. Build result
        $duration = (microtime(true) - $startTime) * 1000;

        return new ScenarioResult(
            machineId: $machine?->state?->history?->first()?->root_event_id ?? '',
            rootEventId: $machine?->state?->history?->first()?->root_event_id ?? '',
            currentState: $machine?->state?->currentStateDefinition?->key ?? '',
            models: array_merge($parentModels, $scenario->getCreatedModels()),
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
     *
     * Merges defaults: child defaults override parent defaults.
     * Merges arrange: child stubs override parent stubs for same class key.
     * Merges models: parent models created first, child can add or override by name.
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

        // Merge defaults: child overrides parent
        $mergedParams = array_merge($parentScenario->getDefaults(), $scenario->getDefaults(), $params);

        return $this->play($parentScenario, $mergedParams);
    }

    /**
     * Play a child machine's scenario against its spawned instance.
     */
    private function playChildScenario(ChildScenarioStep $step, ?Machine $parentMachine): ?ScenarioResult
    {
        if ($step->getScenarioClass() === null) {
            // No scenario defined for this child — dispatch was suppressed, nothing to do
            return null;
        }

        if (!$parentMachine instanceof Machine) {
            throw new ScenarioFailedException(
                stepIndex: 0,
                eventType: 'child:'.$step->machineClass,
                currentState: 'unknown',
                rejectionReason: 'Cannot play child scenario — parent machine has not been created yet',
            );
        }

        // Look up the child machine from machine_children table
        $parentRootEventId = $parentMachine->state->history->first()->root_event_id;
        $childRecord       = MachineChild::query()
            ->where('parent_root_event_id', $parentRootEventId)
            ->where('child_machine_class', $step->machineClass)
            ->latest()
            ->first();

        if ($childRecord === null) {
            throw new ScenarioFailedException(
                stepIndex: 0,
                eventType: 'child:'.$step->machineClass,
                currentState: $parentMachine->state->currentStateDefinition->key ?? 'unknown',
                rejectionReason: "Child machine {$step->machineClass} not found in machine_children for parent {$parentRootEventId}",
            );
        }

        // Play the child scenario
        $scenarioClass = $step->getScenarioClass();
        /** @var MachineScenario $childScenario */
        $childScenario = new $scenarioClass();
        $childScenario->hydrate($step->getParams());

        // Register child stubs
        $this->registerStubs($childScenario);

        // Create child models
        $this->createModels($childScenario);

        // Replay child steps against the child machine
        /** @var class-string<Machine> $childMachineClass */
        $childMachineClass = $childScenario->getMachine();
        $childMachine      = $childMachineClass::create(state: $childRecord->child_root_event_id);

        $childStepsExecuted = 0;
        foreach ($childScenario->getSteps() as $childStep) {
            if ($childStep instanceof ScenarioStep) {
                $childMachine->send([
                    'type'    => $childStep->eventType,
                    'payload' => $childStep->payload,
                ]);
                $childStepsExecuted++;
            }
        }

        return new ScenarioResult(
            machineId: $childRecord->child_root_event_id,
            rootEventId: $childRecord->child_root_event_id,
            currentState: $childMachine->state->currentStateDefinition->key ?? '',
            models: [],
            stepsExecuted: $childStepsExecuted,
            duration: 0,
        );
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
