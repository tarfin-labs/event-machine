<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Illuminate\Support\Arr;
use Tarfinlabs\EventMachine\TypedData;
use Illuminate\Support\Facades\Validator;
use Illuminate\Contracts\Support\Arrayable;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Enums\SourceType;
use Illuminate\Support\Traits\InteractsWithData;
use Tarfinlabs\EventMachine\Exceptions\MachineEventValidationException;

/**
 * Class EventBehavior.
 *
 * Abstract base for event classes. Extends TypedData for reflection-based
 * from()/toArray(), cast resolution, and validation.
 *
 * Supports two modes:
 * - Typed: subclass declares constructor properties → payload data as real properties
 * - Untyped: no constructor properties → payload stored in $payload array
 */
abstract class EventBehavior extends TypedData
{
    use InteractsWithData;

    // region Infrastructure Fields

    public ?string $type         = null;
    public ?array $payload       = null;
    public int $version          = 1;
    public SourceType $source    = SourceType::EXTERNAL;
    public bool $isTransactional = true;
    private mixed $actor         = null;

    /**
     * Constructor for untyped events and direct instantiation (engine internal).
     * Typed event subclasses override this with their own constructor params.
     */
    public function __construct(
        ?string $type = null,
        ?array $payload = null,
        ?bool $isTransactional = null,
        mixed $actor = null,
        int $version = 1,
        SourceType $source = SourceType::EXTERNAL,
    ) {
        $this->type    = $type ?? static::getType();
        $this->payload = $payload;
        $this->version = $version;
        $this->source  = $source;
        $this->actor   = $actor;

        if ($isTransactional !== null) {
            $this->isTransactional = $isTransactional;
        }
    }

    /** @var array<int, string> */
    private static array $infrastructure = [
        'type', 'payload', 'version', 'source', 'isTransactional',
    ];

    // endregion

    // region Template Method Overrides

    protected static function extractInputData(array $data): array
    {
        return $data['payload'] ?? [];
    }

    protected static function isInfrastructureField(string $name): bool
    {
        return in_array($name, self::$infrastructure, true);
    }

    protected static function hydrateInfrastructure(TypedData $instance, array $data): void
    {
        /* @var EventBehavior $instance */
        $instance->type            = $data['type'] ?? static::getType();
        $instance->version         = $data['version'] ?? 1;
        $instance->isTransactional = $data['isTransactional'] ?? true;
        $instance->source          = isset($data['source'])
            ? ($data['source'] instanceof SourceType ? $data['source'] : SourceType::from($data['source']))
            : SourceType::EXTERNAL;
        $instance->actor = $data['actor'] ?? null;

        $instance->payload = static::hasUserProperties() ? null : $data['payload'] ?? null;
    }

    protected function buildArray(array $properties): array
    {
        return [
            'type'            => $this->type,
            'payload'         => $properties ?: ($this->payload ?? []),
            'version'         => $this->version,
            'isTransactional' => $this->isTransactional,
            'source'          => $this->source->value,
        ];
    }

    // endregion

    // region Typed/Untyped Detection

    protected static function hasUserProperties(): bool
    {
        return static::getUserProperties() !== [];
    }

    // endregion

    // region Canonical Payload Accessor

    /**
     * Returns the payload as an array — works in both typed and untyped mode.
     *
     * - Typed: computes from typed public properties (raw, unserialized values)
     * - Untyped: returns $this->payload (the stored array)
     *
     * Engine code and internal events should use this instead of $this->payload.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        if (static::hasUserProperties()) {
            $result = [];

            foreach (static::getUserProperties() as $name => $prop) {
                $result[$name] = $prop->getValue($this);
            }

            return $result;
        }

        return $this->payload ?? [];
    }

    // endregion

    // region Validation Override

    /**
     * Always validates flat payload keys — no 'payload.' prefix needed.
     * Infrastructure fields (type, version, source) are the engine's
     * responsibility, not the event author's.
     *
     * Both typed and untyped use flat keys: 'vehicle_id', not 'payload.vehicle_id'.
     */
    public function selfValidate(): void
    {
        if (static::hasUserProperties()) {
            $serialized = [];

            foreach (static::getUserProperties() as $name => $prop) {
                $serialized[$name] = static::serializeValue($name, $prop->getValue($this));
            }

            static::performValidation($serialized);
        } else {
            static::performValidation($this->payload ?? []);
        }
    }

    public static function validateAndCreate(array|Arrayable $data): static
    {
        static::performValidation($data['payload'] ?? []);

        return static::from($data);
    }

    public static function stopOnFirstFailure(): bool
    {
        return true;
    }

    protected static function performValidation(array $data): void
    {
        $rules = static::rules();

        if ($rules === []) {
            return;
        }

        $validator = Validator::make($data, $rules, static::messages());

        if (static::stopOnFirstFailure()) {
            $validator->stopOnFirstFailure();
        }

        if ($validator->fails()) {
            throw new MachineEventValidationException($validator);
        }
    }

    // endregion

    // region Magic Property Access

    /**
     * $event->someKey delegates to payload via __get.
     *
     * On typed events: declared properties are accessed directly (PHP skips __get).
     *   $event->vehicle_id → real property (no __get).
     *   $event->unknown_key → __get fires → data('unknown_key') → null.
     * On untyped events: all payload keys go through __get.
     */
    public function __get(string $name): mixed
    {
        return $this->data($name);
    }

    public function __isset(string $name): bool
    {
        return isset($this->payload()[$name]);
    }

    // endregion

    // region Abstract & API

    abstract public static function getType(): string;

    public function actor(ContextManager $context): mixed
    {
        return $this->actor;
    }

    public function getScenario(): ?string
    {
        return $this->payload()['scenarioType'] ?? null;
    }

    /**
     * Get all of the input and files for the request.
     *
     * @param  array|mixed|null  $keys
     */
    public function all(mixed $keys = null): array
    {
        $input = $this->payload();

        if (!$keys) {
            return $input;
        }

        $results = [];

        foreach (is_array($keys) ? $keys : func_get_args() as $key) {
            Arr::set($results, $key, Arr::get($input, $key));
        }

        return $results;
    }

    /**
     * Retrieve data from the instance.
     */
    public function data(mixed $key = null, mixed $default = null): mixed
    {
        return data_get($this->all(), $key, $default);
    }

    // collect(), only(), except() — provided by InteractsWithData trait

    /**
     * Convert the event to an array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $properties = [];

        foreach (static::getUserProperties() as $name => $prop) {
            $properties[$name] = static::serializeValue($name, $prop->getValue($this));
        }

        return $this->buildArray($properties);
    }

    public static function forTesting(array $attributes = []): static
    {
        return static::from(array_merge([
            'type'    => static::getType(),
            'payload' => [],
            'version' => 1,
        ], $attributes));
    }

    // endregion
}
