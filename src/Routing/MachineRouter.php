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

        if ($model !== null && $attribute === null) {
            throw new \InvalidArgumentException(
                "MachineRouter: 'attribute' is required when 'model' is set."
            );
        }

        $machineIdFor = $options['machineIdFor'] ?? [];
        $middleware   = $options['middleware'] ?? [];
        $namePrefix   = $options['name'] ?? $definition->id;

        Route::prefix($prefix)
            ->middleware($middleware)
            ->group(function () use (
                $endpoints, $machineClass, $model, $attribute,
                $create, $machineIdFor, $namePrefix,
            ): void {
                if ($create) {
                    Route::post('/create', [MachineController::class, 'handleCreate'])
                        ->name("{$namePrefix}.create")
                        ->setDefaults(['_machine_class' => $machineClass]);
                }

                foreach ($endpoints as $eventType => $endpoint) {
                    $isMachineIdBound = in_array($eventType, $machineIdFor, true);

                    if ($model === null) {
                        $routeUri = $endpoint->uri;
                        $handler  = 'handleStateless';
                    } elseif ($isMachineIdBound) {
                        $routeUri = "/{machineId}{$endpoint->uri}";
                        $handler  = 'handleMachineIdBound';
                    } else {
                        $modelParam = Str::camel(class_basename($model));
                        $routeUri   = "/{{$modelParam}}{$endpoint->uri}";
                        $handler    = 'handleModelBound';
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
