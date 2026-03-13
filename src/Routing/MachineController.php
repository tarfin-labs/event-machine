<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Routing;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Actor\Machine;
use Illuminate\Validation\ValidationException;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;
use Tarfinlabs\EventMachine\Jobs\ChildMachineCompletionJob;
use Tarfinlabs\EventMachine\Exceptions\MachineValidationException;

class MachineController extends Controller
{
    /**
     * Model-bound endpoint handler.
     * Route: /{model}/{uri}.
     */
    public function handleModelBound(Request $request): JsonResponse
    {
        $route = $request->route();

        $modelParam = $route->parameterNames()[0];
        $model      = $route->parameter($modelParam);
        $machine    = $model->{$route->defaults['_model_attribute']};

        return $this->handleEndpoint($machine, $request);
    }

    /**
     * MachineId-bound endpoint handler.
     * Route: /{machineId}/{uri}.
     */
    public function handleMachineIdBound(Request $request): JsonResponse
    {
        $route        = $request->route();
        $machineClass = $route->defaults['_machine_class'];
        $machine      = $machineClass::create(state: $route->parameter('machineId'));

        return $this->handleEndpoint($machine, $request);
    }

    /**
     * Stateless endpoint handler.
     * Route: /{uri}.
     *
     * Creates a fresh machine per request — no persistence, no model.
     */
    public function handleStateless(Request $request): JsonResponse
    {
        $machineClass = $request->route()->defaults['_machine_class'];
        $machine      = $machineClass::create();

        return $this->handleEndpoint($machine, $request);
    }

    /**
     * Create endpoint handler.
     * Route: POST /create.
     */
    public function handleCreate(Request $request): JsonResponse
    {
        $machineClass = $request->route()->defaults['_machine_class'];

        $machine = $machineClass::create();
        $machine->persist();

        return $this->buildResponse($machine->state, $machine, resultKey: null, statusCode: 201);
    }

    /**
     * Shared endpoint handler — extracts route defaults and runs the endpoint lifecycle.
     */
    protected function handleEndpoint(Machine $machine, Request $request): JsonResponse
    {
        $defaults = $request->route()->defaults;

        $event = $this->resolveEvent($machine, $defaults['_event_type'], $request);

        return $this->executeEndpoint(
            machine: $machine,
            event: $event,
            actionClass: $defaults['_action_class'] ?? null,
            resultKey: $defaults['_result_behavior'] ?? null,
            statusCode: $defaults['_status_code'] ?? 200,
        );
    }

    /**
     * Resolve an event from the request using Spatie Data validateAndCreate().
     */
    protected function resolveEvent(Machine $machine, string $eventType, Request $request): EventBehavior
    {
        $eventClass = $machine->definition->behavior['events'][$eventType]
            ?? throw new \RuntimeException("Event type '{$eventType}' not found in behavior.");

        return $eventClass::validateAndCreate($request->all());
    }

    /**
     * Execute the endpoint lifecycle: action.before -> send -> action.after -> response.
     */
    protected function executeEndpoint(
        Machine $machine,
        EventBehavior $event,
        ?string $actionClass,
        ?string $resultKey,
        int $statusCode,
    ): JsonResponse {
        $action = $actionClass !== null
            ? resolve($actionClass)->withMachineContext($machine, $machine->state)
            : null;

        $action?->before();

        try {
            $state = $machine->send(event: $event);
        } catch (MachineValidationException $e) { // @phpstan-ignore catch.neverThrown
            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => method_exists($e, 'errors') ? $e->errors() : [],
            ], 422);
        } catch (ValidationException $e) { // @phpstan-ignore catch.neverThrown
            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            $response = $action?->onException($e);

            if ($response !== null) {
                return $response;
            }

            throw $e;
        }

        if ($action !== null) {
            $action->withMachineContext($machine, $state);
        }

        $action?->after();

        // Auto-dispatch completion if child reached final state and has a parent
        $this->dispatchChildCompletionIfFinal($machine, $state);

        return $this->buildResponse($state, $machine, $resultKey, $statusCode);
    }

    /**
     * Build the JSON response — either from ResultBehavior or default State serialization.
     */
    protected function buildResponse(
        State $state,
        Machine $machine,
        ?string $resultKey,
        int $statusCode,
    ): JsonResponse {
        if ($resultKey !== null) {
            $result = $this->resolveAndRunResult($resultKey, $state, $machine);

            return response()->json(['data' => $result], $statusCode);
        }

        $rootEventId = $state->history->first()?->root_event_id;

        return response()->json([
            'data' => [
                'machine_id' => $rootEventId,
                'value'      => $state->value,
                'context'    => $state->context->toArray(),
            ],
        ], $statusCode);
    }

    /**
     * Resolve and run a ResultBehavior using the InvokableBehavior parameter injection pattern.
     */
    protected function resolveAndRunResult(
        string $resultKey,
        State $state,
        Machine $machine,
    ): mixed {
        $resultClass = class_exists($resultKey)
            ? $resultKey
            : ($machine->definition->behavior['results'][$resultKey] ?? null);

        if ($resultClass === null) {
            throw new \RuntimeException("Result behavior '{$resultKey}' not found.");
        }

        $resultBehavior = resolve($resultClass);

        $params = InvokableBehavior::injectInvokableBehaviorParameters(
            actionBehavior: $resultBehavior,
            state: $state,
            eventBehavior: $state->currentEventBehavior,
        );

        return $resultBehavior(...$params);
    }

    /**
     * If the machine reached a final state and is a tracked child, dispatch completion to parent.
     *
     * This enables the webhook pattern: child machine receives endpoint event,
     * transitions to final, and auto-dispatches ChildMachineCompletionJob.
     */
    protected function dispatchChildCompletionIfFinal(Machine $machine, State $state): void
    {
        if ($state->currentStateDefinition->type !== StateDefinitionType::FINAL) {
            return;
        }

        $rootEventId = $state->history->first()?->root_event_id;

        if ($rootEventId === null) {
            return;
        }

        // Find the MachineChild tracking record for this child
        $childRecord = MachineChild::where('child_root_event_id', $rootEventId)
            ->whereNotIn('status', [MachineChild::STATUS_COMPLETED, MachineChild::STATUS_FAILED, MachineChild::STATUS_CANCELLED, MachineChild::STATUS_TIMED_OUT])
            ->first();

        if ($childRecord === null) {
            return;
        }

        $childRecord->markCompleted();

        dispatch(new ChildMachineCompletionJob(
            parentRootEventId: $childRecord->parent_root_event_id,
            parentMachineClass: $childRecord->parent_machine_class ?? $machine->definition->machineClass ?? '',
            parentStateId: $childRecord->parent_state_id,
            childMachineClass: $childRecord->child_machine_class,
            childRootEventId: $rootEventId,
            success: true,
            result: $machine->result(),
            childContextData: $state->context->data,
        ));
    }
}
