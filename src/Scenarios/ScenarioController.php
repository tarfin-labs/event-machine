<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class ScenarioController extends Controller
{
    /**
     * Play a scenario on a new machine.
     */
    public function play(string $scenario, Request $request): JsonResponse
    {
        $machineClass  = $request->route()->defaults['_machine_class'] ?? null;
        $scenarioClass = $this->resolveScenarioClass($scenario, $machineClass);

        if ($scenarioClass === null) {
            return response()->json(['error' => "Scenario '{$scenario}' not found."], 404);
        }

        $params = $request->input('params', []);

        try {
            $result = $scenarioClass::play($params);

            return $this->scenarioResultResponse($scenarioClass, $result);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'type'  => class_basename($e),
            ], 422);
        }
    }

    /**
     * Play a scenario on an existing machine (mid-flight).
     */
    public function playOn(string $scenario, string $machineId, Request $request): JsonResponse
    {
        $machineClass  = $request->route()->defaults['_machine_class'] ?? null;
        $scenarioClass = $this->resolveScenarioClass($scenario, $machineClass);

        if ($scenarioClass === null) {
            return response()->json(['error' => "Scenario '{$scenario}' not found."], 404);
        }

        $params = $request->input('params', []);

        try {
            $result = $scenarioClass::playOn($machineId, $params);

            return $this->scenarioResultResponse($scenarioClass, $result);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'type'  => class_basename($e),
            ], 422);
        }
    }

    /**
     * List scenarios — scoped to machine when _machine_class is set via route defaults.
     */
    public function list(Request $request): JsonResponse
    {
        $machineClass = $request->route()->defaults['_machine_class'] ?? null;

        $scenarios = $machineClass !== null
            ? ScenarioDiscovery::forMachine($machineClass)
            : ScenarioDiscovery::discover();

        $grouped = [];

        foreach ($scenarios as $scenarioClass) {
            /** @var MachineScenario $instance */
            $instance = new $scenarioClass();
            $machine  = class_basename($instance->getMachine());

            $grouped[$machine][] = [
                'class'       => class_basename($scenarioClass),
                'slug'        => $this->classToSlug(class_basename($scenarioClass)),
                'description' => $instance->getDescription(),
                'from'        => $instance->getFrom(),
                'parent'      => $instance->getParent() ? class_basename($instance->getParent()) : null,
                'defaults'    => $instance->getDefaults(),
            ];
        }

        return response()->json(['scenarios' => $grouped]);
    }

    /**
     * Describe a specific scenario.
     */
    public function describe(string $scenario, Request $request): JsonResponse
    {
        $machineClass  = $request->route()->defaults['_machine_class'] ?? null;
        $scenarioClass = $this->resolveScenarioClass($scenario, $machineClass);

        if ($scenarioClass === null) {
            return response()->json(['error' => "Scenario '{$scenario}' not found."], 404);
        }

        /** @var MachineScenario $instance */
        $instance = new $scenarioClass();

        return response()->json([
            'class'       => class_basename($scenarioClass),
            'machine'     => class_basename($instance->getMachine()),
            'description' => $instance->getDescription(),
            'from'        => $instance->getFrom(),
            'parent'      => $instance->getParent() ? class_basename($instance->getParent()) : null,
            'defaults'    => $instance->getDefaults(),
        ]);
    }

    /**
     * @return class-string<MachineScenario>|null
     */
    private function resolveScenarioClass(string $slug, ?string $machineClass = null): ?string
    {
        $scenarios = $machineClass !== null
            ? ScenarioDiscovery::forMachine($machineClass)
            : ScenarioDiscovery::discover();

        foreach ($scenarios as $class) {
            if ($this->classToSlug(class_basename($class)) === $slug) {
                return $class;
            }
        }

        return null;
    }

    /**
     * @param  class-string<MachineScenario>  $scenarioClass
     */
    private function scenarioResultResponse(string $scenarioClass, ScenarioResult $result): JsonResponse
    {
        return response()->json([
            'scenario'       => class_basename($scenarioClass),
            'machine'        => class_basename((new $scenarioClass())->getMachine()),
            'machine_id'     => $result->machineId,
            'current_state'  => $result->currentState,
            'root_event_id'  => $result->rootEventId,
            'models'         => $result->models,
            'steps_executed' => $result->stepsExecuted,
            'duration_ms'    => round($result->duration, 1),
        ]);
    }

    private function classToSlug(string $className): string
    {
        return Str::kebab($className);
    }
}
