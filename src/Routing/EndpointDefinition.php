<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Routing;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

/**
 * Value object representing a single endpoint definition.
 *
 * Normalizes various endpoint configuration formats into a consistent structure.
 */
class EndpointDefinition
{
    public function __construct(
        public readonly string $eventType,
        public readonly string $uri,
        public readonly string $method,
        public readonly ?string $actionClass,
        public readonly ?string $resultBehavior,
        public readonly array $middleware,
        public readonly ?int $statusCode,
    ) {}

    /**
     * Create an EndpointDefinition from various configuration formats.
     *
     * Supported formats:
     *   'FARMER_SAVED' => null                        (URI auto-generated)
     *   'FARMER_SAVED' => '/farmer'                   (string shorthand)
     *   'FARMER_SAVED' => ['uri' => '/farmer', ...]   (array config)
     *   FarmerSavedEvent::class => '/farmer'           (event class key)
     */
    public static function fromConfig(string $key, string|array|null $config, ?array $behavior): self
    {
        $eventType = is_subclass_of($key, EventBehavior::class)
            ? $key::getType()
            : $key;

        if (is_string($config)) {
            return new self(
                eventType: $eventType,
                uri: $config,
                method: 'POST',
                actionClass: null,
                resultBehavior: null,
                middleware: [],
                statusCode: null,
            );
        }

        $config ??= [];
        $uri = $config['uri'] ?? self::generateUri($eventType);

        return new self(
            eventType: $eventType,
            uri: $uri,
            method: $config['method'] ?? 'POST',
            actionClass: $config['action'] ?? null,
            resultBehavior: $config['result'] ?? null,
            middleware: $config['middleware'] ?? [],
            statusCode: $config['status'] ?? null,
        );
    }

    /**
     * Generate a URI from an event type.
     *
     * FARMER_SAVED → /farmer-saved
     * APPROVED_WITH_INITIATIVE → /approved-with-initiative
     */
    public static function generateUri(string $eventType): string
    {
        return '/'.str_replace('_', '-', strtolower($eventType));
    }
}
