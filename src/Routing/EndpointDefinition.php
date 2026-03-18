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
    /**
     * @param  array<string>|null  $contextKeys  When set, only these context keys are included in the default response. Null = all keys (backwards compatible).
     */
    public function __construct(
        public readonly string $eventType,
        public readonly string $uri,
        public readonly string $method,
        public readonly ?string $actionClass,
        public readonly ?string $resultBehavior,
        public readonly array $middleware,
        public readonly ?int $statusCode,
        public readonly ?array $contextKeys = null,
        public readonly ?bool $availableEvents = null,
    ) {}

    /**
     * Resolve an event key to its SCREAMING_SNAKE_CASE event type.
     *
     * Accepts either a plain string ('SUBMIT') or an EventBehavior class FQCN.
     */
    public static function resolveEventType(string $key): string
    {
        return is_subclass_of($key, EventBehavior::class)
            ? $key::getType()
            : $key;
    }

    /**
     * Create an EndpointDefinition from various configuration formats.
     *
     * Supported formats:
     *   'FARMER_SAVED'                                (list syntax, auto-generated)
     *   FarmerSavedEvent::class                       (list syntax with event class)
     *   'FARMER_SAVED' => '/farmer'                   (string shorthand)
     *   'FARMER_SAVED' => ['uri' => '/farmer', ...]   (array config)
     *   FarmerSavedEvent::class => '/farmer'           (event class key)
     */
    public static function fromConfig(string $key, string|array|null $config = null): self
    {
        $eventType = self::resolveEventType($key);

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
            contextKeys: $config['contextKeys'] ?? null,
            availableEvents: $config['available_events'] ?? null,
        );
    }

    /**
     * Generate a URI from an event type.
     *
     * FARMER_SAVED → /farmer-saved
     * CONSENT_GRANTED_EVENT → /consent-granted
     * APPROVED_WITH_INITIATIVE → /approved-with-initiative
     */
    public static function generateUri(string $eventType): string
    {
        if (str_ends_with($eventType, '_EVENT')) {
            $eventType = substr($eventType, 0, -6);
        }

        return '/'.str_replace('_', '-', strtolower($eventType));
    }
}
