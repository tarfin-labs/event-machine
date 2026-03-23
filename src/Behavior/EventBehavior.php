<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Enums\SourceType;
use Illuminate\Support\Traits\InteractsWithData;
use Tarfinlabs\EventMachine\Exceptions\MachineEventValidationException;

/**
 * Class EventBehavior.
 *
 * Represents the behavior of an event.
 */
abstract class EventBehavior
{
    use InteractsWithData;

    /** Actor performing the event. */
    private mixed $actor = null;

    public bool $isTransactional = true;

    /**
     * Creates a new instance of the class.
     *
     * @param  ?string  $type  The type of the object. Default is null.
     * @param  ?array  $payload  The payload to be associated with the object. Default is null.
     * @param  mixed  $actor  Actor performing the event. Default is null.
     * @param  int  $version  The version number of the object. Default is 1.
     * @param  SourceType  $source  The source type of the object. Default is SourceType::EXTERNAL.
     */
    public function __construct(
        public ?string $type = null,
        public ?array $payload = null,
        ?bool $isTransactional = null,
        mixed $actor = null,
        public int $version = 1,
        public SourceType $source = SourceType::EXTERNAL,
    ) {
        $this->type ??= static::getType();

        if ($isTransactional !== null) {
            $this->isTransactional = $isTransactional;
        }

        $this->actor = $actor;
    }

    /**
     * Gets the type of the object.
     *
     * @return string The type of the object.
     */
    abstract public static function getType(): string;

    // region Validation

    /**
     * Laravel validation rules — override in subclasses.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [];
    }

    /**
     * Indicates if the validator should stop on the first rule failure.
     */
    public static function stopOnFirstFailure(): bool
    {
        return true;
    }

    /**
     * Validates the object by calling performValidation() and handles any validation exceptions.
     *
     * @throws MachineEventValidationException If the object fails validation.
     */
    public function selfValidate(): void
    {
        static::performValidation(
            ['type' => $this->type, 'payload' => $this->payload, 'version' => $this->version]
        );
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

    // region Factory

    /**
     * Create an event instance from an array.
     *
     * EventBehavior fields are fixed — users extend via payload array.
     * Hardcoded from() is faster and more explicit than reflection.
     */
    public static function from(array $data): static
    {
        return new static(
            type: $data['type'] ?? null,
            payload: $data['payload'] ?? null,
            isTransactional: $data['isTransactional'] ?? null,
            actor: $data['actor'] ?? null,
            version: $data['version'] ?? 1,
            source: isset($data['source']) ? (
                $data['source'] instanceof SourceType ? $data['source'] : SourceType::from($data['source'])
            ) : SourceType::EXTERNAL,
        );
    }

    public static function validateAndCreate(array $data): static
    {
        static::performValidation($data);

        return static::from($data);
    }

    /**
     * Create an event instance for testing with sensible defaults.
     * Override in concrete classes for domain-specific defaults.
     *
     * @param  array  $attributes  Attributes to merge with defaults.
     */
    public static function forTesting(array $attributes = []): static
    {
        return static::from(array_merge([
            'type'    => static::getType(),
            'payload' => [],
            'version' => 1,
        ], $attributes));
    }

    // endregion

    // region API

    public function actor(ContextManager $context): mixed
    {
        return $this->actor;
    }

    /**
     * Retrieves the scenario value from the payload.
     */
    public function getScenario(): ?string
    {
        return $this->payload['scenarioType'] ?? null;
    }

    /**
     * Get all of the input and files for the request.
     *
     * @param  array|mixed|null  $keys
     */
    public function all(mixed $keys = null): array
    {
        $input = $this->payload ?? [];

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
     *
     * @param  string  $key
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
        return [
            'type'            => $this->type,
            'payload'         => $this->payload,
            'version'         => $this->version,
            'isTransactional' => $this->isTransactional,
            'source'          => $this->source->value,
        ];
    }

    // endregion
}
