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
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
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
        $route          = $request->route();
        $parameterNames = $route->parameterNames();

        if ($parameterNames === []) {
            abort(500, 'Model-bound endpoint requires a route model parameter.');
        }

        $modelParam     = $parameterNames[0];
        $model          = $route->parameter($modelParam);
        $modelAttribute = $route->defaults['_model_attribute'] ?? null;

        if ($model === null || $modelAttribute === null) {
            abort(500, 'Route model or model attribute not found for model-bound endpoint.');
        }

        $machine = $model->{$modelAttribute};

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
            contextKeys: $defaults['_context_keys'] ?? null,
            includeAvailableEvents: $defaults['_available_events'] ?? true,
        );
    }

    /**
     * Resolve an event from the request using validateAndCreate().
     */
    protected function resolveEvent(Machine $machine, string $eventType, Request $request): EventBehavior
    {
        $eventClass = $machine->definition->behavior['events'][$eventType] ?? null;

        if ($eventClass === null) {
            abort(422, "Event type '{$eventType}' not found in behavior.");
        }

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
        ?array $contextKeys = null,
        ?bool $includeAvailableEvents = true,
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

        return $this->buildResponse($state, $machine, $resultKey, $statusCode, $contextKeys, $includeAvailableEvents);
    }

    /**
     * Build the JSON response — either from ResultBehavior or default State serialization.
     */
    protected function buildResponse(
        State $state,
        Machine $machine,
        ?string $resultKey,
        int $statusCode,
        ?array $contextKeys = null,
        ?bool $includeAvailableEvents = true,
    ): JsonResponse {
        if ($resultKey !== null) {
            $result = $this->resolveAndRunResult($resultKey, $state, $machine);

            return response()->json(['data' => $result], $statusCode);
        }

        $rootEventId = $state->history->first()?->root_event_id;
        $contextData = $state->context->toResponseArray();

        // Filter context keys if specified in endpoint config
        if ($contextKeys !== null) {
            $contextData = array_intersect_key($contextData, array_flip($contextKeys));
        }

        $response = [
            'machine_id' => $rootEventId,
            'value'      => $state->value,
            'context'    => $contextData,
        ];

        if ($includeAvailableEvents !== false) {
            $response['available_events'] = $state->availableEvents();
        }

        return response()->json(['data' => $response], $statusCode);
    }

    /**
     * Resolve and run a ResultBehavior using the InvokableBehavior parameter injection pattern.
     *
     * When a ForwardContext is provided, it is injected into the ResultBehavior
     * so it can access the child machine's state and context.
     */
    protected function resolveAndRunResult(
        string $resultKey,
        State $state,
        Machine $machine,
        ?ForwardContext $forwardContext = null,
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
            eventBehavior: $state->triggeringEvent ?? $state->currentEventBehavior,
            forwardContext: $forwardContext,
        );

        return $resultBehavior(...$params);
    }

    /**
     * Forwarded model-bound endpoint handler.
     * Route: /{model}/{uri} — resolves parent machine via Eloquent model binding.
     */
    public function handleForwardedModelBound(Request $request): JsonResponse
    {
        $route          = $request->route();
        $parameterNames = $route->parameterNames();

        if ($parameterNames === []) {
            abort(500, 'Forwarded model-bound endpoint requires a route model parameter.');
        }

        $modelParam     = $parameterNames[0];
        $model          = $route->parameter($modelParam);
        $modelAttribute = $route->defaults['_model_attribute'] ?? null;

        if ($model === null || $modelAttribute === null) {
            abort(500, 'Route model or model attribute not found for forwarded model-bound endpoint.');
        }

        $machine = $model->{$modelAttribute};

        return $this->executeForwardedEndpoint($machine, $request);
    }

    /**
     * Forwarded machineId-bound endpoint handler.
     * Route: /{machineId}/{uri} — resolves parent machine via root_event_id.
     */
    public function handleForwardedMachineIdBound(Request $request): JsonResponse
    {
        $route        = $request->route();
        $machineClass = $route->defaults['_machine_class'];
        $machine      = $machineClass::create(state: $route->parameter('machineId'));

        return $this->executeForwardedEndpoint($machine, $request);
    }

    /**
     * Shared forwarded endpoint logic: validate with child's EventBehavior,
     * run parent action lifecycle, send to parent (triggers tryForwardEventToChild),
     * build response with child state.
     */
    protected function executeForwardedEndpoint(Machine $machine, Request $request): JsonResponse
    {
        $defaults = $request->route()->defaults;

        // 1. Resolve event using CHILD's EventBehavior class
        $childEventClass = $defaults['_child_event_class'];
        $event           = $childEventClass::validateAndCreate($request->all());

        // 2. Run parent-level action.before() if configured
        $actionClass = $defaults['_action_class'] ?? null;
        $action      = $actionClass !== null
            ? resolve($actionClass)->withMachineContext($machine, $machine->state)
            : null;

        $action?->before();

        // 3. Send to parent using PARENT event type (tryForwardEventToChild resolves
        //    the forward mapping by parent event type, not child event type).
        //    The child EventBehavior was used for validation above; now we send
        //    with parent type + validated payload so the forward mapping works
        //    correctly for rename and child_event configurations.
        $parentEventType = $defaults['_event_type'];

        try {
            $state = $machine->send([
                'type'    => $parentEventType,
                'payload' => $event->payload ?? [],
            ]);
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

        // 4. Run parent-level action.after()
        if ($action !== null) {
            $action->withMachineContext($machine, $state);
        }

        $action?->after();

        // 5. Build response with child state info
        return $this->buildForwardedResponse($machine, $state, $defaults);
    }

    /**
     * Build response for forwarded endpoints — includes parent + child state.
     */
    protected function buildForwardedResponse(Machine $machine, State $state, array $defaults): JsonResponse
    {
        $resultKey   = $defaults['_result_behavior'] ?? null;
        $contextKeys = $defaults['_context_keys'] ?? null;
        $statusCode  = $defaults['_status_code'] ?? 200;

        $childState = $state->getForwardedChildState();

        // Custom result behavior — runs on PARENT with ForwardContext injected
        if ($resultKey !== null && $childState instanceof State) {
            $forwardContext = new ForwardContext(
                childContext: $childState->context,
                childState: $childState,
            );

            $result = $this->resolveAndRunResult(
                resultKey: $resultKey,
                state: $state,
                machine: $machine,
                forwardContext: $forwardContext,
            );

            return response()->json(['data' => $result], $statusCode);
        }

        // Default response: parent state + child state
        $rootEventId = $state->history->first()?->root_event_id;

        $response = [
            'machine_id' => $rootEventId,
            'value'      => $state->value,
        ];

        if ($childState instanceof State) {
            $childContext = $childState->context->toResponseArray();

            if ($contextKeys !== null) {
                $childContext = array_intersect_key($childContext, array_flip($contextKeys));
            }

            $response['child'] = [
                'value'   => $childState->value,
                'context' => $childContext,
            ];
        }

        $includeAvailableEvents = $defaults['_available_events'] ?? null;

        if ($includeAvailableEvents !== false) {
            $response['available_events'] = $state->availableEvents();
        }

        return response()->json(['data' => $response], $statusCode);
    }

    /**
     * If the machine reached a final state and is a tracked child, dispatch completion to parent.
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
            childContextData: $state->context->toArray(),
            outputData: MachineDefinition::resolveChildOutput(
                $state->currentStateDefinition,
                $state->context,
            ),
            childFinalState: $state->currentStateDefinition->key,
        ));
    }
}
