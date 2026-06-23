<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Routing;

use Tarfinlabs\EventMachine\Exceptions\InvalidRouterConfigException;

/**
 * Value object representing a single read-only HTTP projection of a machine's state.
 *
 * Reads are the query side of the command/query split: GET-only, machineId-bound,
 * zero-write projections served by MachineController::handleSnapshot(). They never
 * construct an event, never call send()/persist(), and never acquire a lock.
 *
 * Mirrors {@see EndpointDefinition} (the command side) minus the keys that imply a
 * side effect (action) or a non-GET method.
 */
class ReadDefinition
{
    /** @var array<int, string> */
    private const array RECOGNIZED_KEYS = ['uri', 'output', 'middleware', 'status', 'available_events'];

    /**
     * @param  null|string|array<int|string, mixed>  $output
     * @param  array<int, string>  $middleware
     */
    public function __construct(
        public readonly string $name,
        public readonly string $uri,
        public readonly null|string|array $output,
        public readonly array $middleware,
        public readonly int $statusCode,
        public readonly ?bool $availableEvents,
    ) {}

    /**
     * Create a ReadDefinition from a read config value.
     *
     * Supported value forms:
     *   'status' => null                       (all defaults)
     *   'status' => '/custom-uri'              (string URI shorthand)
     *   'status' => ['output' => ..., ...]     (options array)
     *
     * @param  null|string|array<string, mixed>  $config
     *
     * @throws InvalidRouterConfigException on a forbidden key (action/method), an
     *                                      unrecognized key, or an invalid URI.
     */
    public static function fromConfig(string $key, null|string|array $config = null): self
    {
        // String shorthand → treat as a uri override.
        if (is_string($config)) {
            $config = ['uri' => $config];
        }

        $config ??= [];

        foreach (array_keys($config) as $optionKey) {
            if ($optionKey === 'action' || $optionKey === 'method') {
                throw InvalidRouterConfigException::forbiddenReadKey($optionKey);
            }

            if (!in_array($optionKey, self::RECOGNIZED_KEYS, true)) {
                throw InvalidRouterConfigException::unknownReadKey($optionKey, self::RECOGNIZED_KEYS);
            }
        }

        /** @var null|string|array<int|string, mixed> $output */
        $output = $config['output'] ?? null;

        /** @var array<int, string> $middleware */
        $middleware = $config['middleware'] ?? [];

        return new self(
            name: $key,
            uri: self::normalizeUri(is_string($config['uri'] ?? null) ? $config['uri'] : $key),
            output: $output,
            middleware: $middleware,
            statusCode: (int) ($config['status'] ?? 200),
            availableEvents: isset($config['available_events']) ? (bool) $config['available_events'] : null,
        );
    }

    /**
     * Normalize a read URI: strip surrounding slashes and prepend exactly one '/'.
     *
     * Rejects empty / slash-only / placeholder-containing URIs. Multi-segment URIs
     * are allowed (e.g. 'status/full' → '/status/full').
     *
     * @throws InvalidRouterConfigException
     */
    public static function normalizeUri(string $uri): string
    {
        $trimmed = trim($uri, '/');

        if ($trimmed === '' || str_contains($trimmed, '{') || str_contains($trimmed, '}')) {
            throw InvalidRouterConfigException::invalidReadUri($uri);
        }

        return '/'.$trimmed;
    }
}
