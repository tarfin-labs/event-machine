<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Routing;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Route;

/**
 * Registers Laravel routes for machine endpoints.
 *
 * Reads parsed endpoint definitions from a machine class and generates
 * routes with the correct handler, middleware, and route defaults.
 */
class MachineRouter
{
    /**
     * Register routes for a machine's endpoints.
     *
     * @param  string  $machineClass  Machine subclass FQCN.
     * @param  array{
     *     prefix: string,
     *     model?: string,
     *     attribute?: string,
     *     create?: bool,
     *     machineIdFor?: string[],
     *     modelFor?: string[],
     *     middleware?: string[],
     *     name?: string,
     *     only?: string[],
     *     except?: string[],
     * }  $options  Router configuration.
     */
    public static function register(string $machineClass, array $options): void
    {
        $definition         = $machineClass::definition();
        $endpoints          = $definition->parsedEndpoints ?? [];
        $forwardedEndpoints = $definition->forwardedEndpoints ?? [];

        if ($endpoints === [] && $forwardedEndpoints === []) {
            return;
        }

        $prefix    = $options['prefix'];
        $model     = $options['model'] ?? null;
        $attribute = $options['attribute'] ?? null;
        $create    = $options['create'] ?? false;

        $machineIdFor = array_map(EndpointDefinition::resolveEventType(...), $options['machineIdFor'] ?? []);
        $modelFor     = array_map(EndpointDefinition::resolveEventType(...), $options['modelFor'] ?? []);

        // ── Endpoint filtering ──────────────────────────────────────────
        $only   = $options['only'] ?? null;
        $except = $options['except'] ?? null;

        if ($only !== null && $except !== null) {
            throw new \InvalidArgumentException(
                "MachineRouter: 'only' and 'except' cannot be used together."
            );
        }

        $onlyTypes = $only !== null
            ? array_map(EndpointDefinition::resolveEventType(...), $only)
            : null;
        $exceptTypes = $except !== null
            ? array_map(EndpointDefinition::resolveEventType(...), $except)
            : null;

        if ($onlyTypes !== null || $exceptTypes !== null) {
            $allKnownTypes = array_merge(
                array_keys($endpoints),
                array_keys($forwardedEndpoints),
            );

            $filterTypes = $onlyTypes ?? $exceptTypes;
            $filterKey   = $onlyTypes !== null ? 'only' : 'except';
            $unknown     = array_diff($filterTypes, $allKnownTypes);

            if ($unknown !== []) {
                throw new \InvalidArgumentException(
                    sprintf(
                        "MachineRouter: unknown event types in '%s': %s. Available: %s",
                        $filterKey,
                        implode(', ', $unknown),
                        implode(', ', $allKnownTypes),
                    )
                );
            }
        }

        // ── Apply filtering ────────────────────────────────────────────
        if ($onlyTypes !== null) {
            $endpoints = array_filter(
                $endpoints,
                fn (string $eventType): bool => in_array($eventType, $onlyTypes, true),
                ARRAY_FILTER_USE_KEY,
            );
            $forwardedEndpoints = array_filter(
                $forwardedEndpoints,
                fn (string $eventType): bool => in_array($eventType, $onlyTypes, true),
                ARRAY_FILTER_USE_KEY,
            );
        } elseif ($exceptTypes !== null) {
            $endpoints = array_filter(
                $endpoints,
                fn (string $eventType): bool => !in_array($eventType, $exceptTypes, true),
                ARRAY_FILTER_USE_KEY,
            );
            $forwardedEndpoints = array_filter(
                $forwardedEndpoints,
                fn (string $eventType): bool => !in_array($eventType, $exceptTypes, true),
                ARRAY_FILTER_USE_KEY,
            );
        }

        if ($endpoints === [] && $forwardedEndpoints === [] && !$create) {
            return;
        }

        // ── Forwarded endpoints cannot be in machineIdFor / modelFor ────
        $forwardedEventTypes = array_keys($forwardedEndpoints);

        if ($forwardedEventTypes !== []) {
            $forwardedInMachineId = array_intersect($machineIdFor, $forwardedEventTypes);

            if ($forwardedInMachineId !== []) {
                throw new \InvalidArgumentException(
                    sprintf(
                        "MachineRouter: 'machineIdFor' cannot reference forwarded endpoints "
                        .'(they inherit binding mode from parent model config): %s',
                        implode(', ', $forwardedInMachineId),
                    )
                );
            }

            $forwardedInModel = array_intersect($modelFor, $forwardedEventTypes);

            if ($forwardedInModel !== []) {
                throw new \InvalidArgumentException(
                    sprintf(
                        "MachineRouter: 'modelFor' cannot reference forwarded endpoints "
                        .'(they inherit binding mode from parent model config): %s',
                        implode(', ', $forwardedInModel),
                    )
                );
            }
        }

        if ($modelFor !== [] && ($model === null || $attribute === null)) {
            throw new \InvalidArgumentException(
                "MachineRouter: 'model' and 'attribute' are required when 'modelFor' is set."
            );
        }

        $overlap = array_intersect($machineIdFor, $modelFor);

        if ($overlap !== []) {
            throw new \InvalidArgumentException(
                "MachineRouter: events cannot be in both 'machineIdFor' and 'modelFor': ".implode(', ', $overlap)
            );
        }

        $middleware = $options['middleware'] ?? [];
        $namePrefix = $options['name'] ?? $definition->id;

        Route::prefix($prefix)
            ->middleware($middleware)
            ->group(function () use (
                $endpoints, $forwardedEndpoints, $machineClass, $model, $attribute,
                $create, $machineIdFor, $modelFor, $namePrefix,
            ): void {
                if ($create) {
                    Route::post('/create', [MachineController::class, 'handleCreate'])
                        ->name("{$namePrefix}.create")
                        ->setDefaults(['_machine_class' => $machineClass]);
                }

                $modelParam = $model !== null ? Str::camel(class_basename($model)) : null;

                if ($modelParam !== null && $modelFor !== []) {
                    Route::model($modelParam, $model);
                }

                foreach ($endpoints as $eventType => $endpoint) {
                    $isMachineIdBound = in_array($eventType, $machineIdFor, true);
                    $isModelBound     = in_array($eventType, $modelFor, true);

                    if ($isMachineIdBound) {
                        $routeUri = "/{machineId}{$endpoint->uri}";
                        $handler  = 'handleMachineIdBound';
                    } elseif ($isModelBound && $modelParam !== null) {
                        $routeUri = "/{{$modelParam}}{$endpoint->uri}";
                        $handler  = 'handleModelBound';
                    } else {
                        $routeUri = $endpoint->uri;
                        $handler  = 'handleStateless';
                    }

                    $routeName = "{$namePrefix}.".strtolower($eventType);

                    $defaults = [
                        '_machine_class'    => $machineClass,
                        '_event_type'       => $eventType,
                        '_action_class'     => $endpoint->actionClass,
                        '_result_behavior'  => $endpoint->resultBehavior,
                        '_status_code'      => $endpoint->statusCode ?? 200,
                        '_context_keys'     => $endpoint->contextKeys,
                        '_available_events' => $endpoint->availableEvents,
                    ];

                    if ($handler === 'handleModelBound') {
                        $defaults['_model_attribute'] = $attribute;
                    }

                    Route::match(
                        [$endpoint->method],
                        $routeUri,
                        [MachineController::class, $handler]
                    )
                        ->name($routeName)
                        ->middleware($endpoint->middleware)
                        ->setDefaults($defaults);
                }

                // Register forwarded endpoints (auto-discovered from forward config)
                $hasModelBinding = $model !== null && $attribute !== null;

                if ($hasModelBinding && $modelParam !== null && $forwardedEndpoints !== []) {
                    Route::model($modelParam, $model);
                }

                foreach ($forwardedEndpoints as $eventType => $fwdEndpoint) {
                    if ($hasModelBinding && $modelParam !== null) {
                        $fwdRouteUri = "/{{$modelParam}}{$fwdEndpoint->uri}";
                        $fwdHandler  = 'handleForwardedModelBound';
                    } else {
                        $fwdRouteUri = "/{machineId}{$fwdEndpoint->uri}";
                        $fwdHandler  = 'handleForwardedMachineIdBound';
                    }

                    $fwdDefaults = [
                        '_machine_class'       => $machineClass,
                        '_event_type'          => $eventType,
                        '_child_event_type'    => $fwdEndpoint->childEventType,
                        '_child_machine_class' => $fwdEndpoint->childMachineClass,
                        '_child_event_class'   => $fwdEndpoint->childEventClass,
                        '_action_class'        => $fwdEndpoint->actionClass,
                        '_result_behavior'     => $fwdEndpoint->resultBehavior,
                        '_context_keys'        => $fwdEndpoint->contextKeys,
                        '_status_code'         => $fwdEndpoint->statusCode ?? 200,
                        '_available_events'    => $fwdEndpoint->availableEvents,
                    ];

                    if ($hasModelBinding) {
                        $fwdDefaults['_model_attribute'] = $attribute;
                    }

                    Route::match(
                        [$fwdEndpoint->method],
                        $fwdRouteUri,
                        [MachineController::class, $fwdHandler]
                    )
                        ->name("{$namePrefix}.".strtolower($eventType))
                        ->middleware($fwdEndpoint->middleware)
                        ->setDefaults($fwdDefaults);
                }
            });
    }
}
