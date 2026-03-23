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
     * Play a scenario and return the result as JSON.
     */
    public function play(string $scenario, Request $request): JsonResponse
    {
        $scenarioClass = $this->resolveScenarioClass($scenario);

        if ($scenarioClass === null) {
            return response()->json(['error' => "Scenario '{$scenario}' not found."], 404);
        }

        $params = $request->input('params', []);

        try {
            $result = $scenarioClass::play($params);

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
        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'type'  => class_basename($e),
            ], 422);
        }
    }

    /**
     * List all available scenarios grouped by machine.
     */
    public function list(): JsonResponse
    {
        $grouped = [];

        foreach (ScenarioDiscovery::discover() as $scenarioClass) {
            /** @var MachineScenario $instance */
            $instance = new $scenarioClass();
            $machine  = class_basename($instance->getMachine());

            $grouped[$machine][] = [
                'class'       => class_basename($scenarioClass),
                'slug'        => $this->classToSlug(class_basename($scenarioClass)),
                'description' => $instance->getDescription(),
                'parent'      => $instance->getParent() ? class_basename($instance->getParent()) : null,
                'defaults'    => $instance->getDefaults(),
            ];
        }

        return response()->json(['scenarios' => $grouped]);
    }

    /**
     * Describe a specific scenario.
     */
    public function describe(string $scenario): JsonResponse
    {
        $scenarioClass = $this->resolveScenarioClass($scenario);

        if ($scenarioClass === null) {
            return response()->json(['error' => "Scenario '{$scenario}' not found."], 404);
        }

        /** @var MachineScenario $instance */
        $instance = new $scenarioClass();

        return response()->json([
            'class'       => class_basename($scenarioClass),
            'machine'     => class_basename($instance->getMachine()),
            'description' => $instance->getDescription(),
            'parent'      => $instance->getParent() ? class_basename($instance->getParent()) : null,
            'defaults'    => $instance->getDefaults(),
        ]);
    }

    /**
     * @return class-string<MachineScenario>|null
     */
    private function resolveScenarioClass(string $slug): ?string
    {
        foreach (ScenarioDiscovery::discover() as $class) {
            if ($this->classToSlug(class_basename($class)) === $slug) {
                return $class;
            }
        }

        return null;
    }

    private function classToSlug(string $className): string
    {
        return Str::kebab($className);
    }
}
