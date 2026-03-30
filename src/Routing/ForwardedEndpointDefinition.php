<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Routing;

/**
 * Value object representing a single forwarded endpoint definition.
 *
 * Holds all parsed metadata for an endpoint that is auto-generated
 * from a parent machine's `forward` config. Used by MachineRouter
 * to register forwarded routes and by MachineController to handle them.
 */
class ForwardedEndpointDefinition
{
    public function __construct(
        public readonly string $parentEventType,
        public readonly string $childEventType,
        public readonly string $childMachineClass,
        public readonly string $childEventClass,
        public readonly string $uri,
        public readonly string $method = 'POST',
        public readonly ?string $actionClass = null,
        public readonly null|string|array|\Closure $output = null,
        public readonly ?int $statusCode = null,
        public readonly array $middleware = [],
        public readonly ?bool $availableEvents = null,
    ) {}
}
