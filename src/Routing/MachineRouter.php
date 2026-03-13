<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Routing;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Route;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;

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
     * }  $options  Router configuration.
     */
    public static function register(string $machineClass, array $options): void
    {
        $definition = $machineClass::definition();
        $endpoints  = $definition->parsedEndpoints ?? [];

        if ($endpoints === []) {
            return;
        }

        $prefix    = $options['prefix'];
        $model     = $options['model'] ?? null;
        $attribute = $options['attribute'] ?? null;
        $create    = $options['create'] ?? false;

        $resolveEventTypes = static fn (array $entries): array => array_map(
            static fn (string $entry): string => is_subclass_of($entry, EventBehavior::class)
                ? $entry::getType()
                : $entry,
            $entries,
        );

        $machineIdFor = $resolveEventTypes($options['machineIdFor'] ?? []);
        $modelFor     = $resolveEventTypes($options['modelFor'] ?? []);

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
                $endpoints, $machineClass, $model, $attribute,
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
                        '_machine_class'   => $machineClass,
                        '_event_type'      => $eventType,
                        '_action_class'    => $endpoint->actionClass,
                        '_result_behavior' => $endpoint->resultBehavior,
                        '_status_code'     => $endpoint->statusCode ?? 200,
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
            });
    }
}
