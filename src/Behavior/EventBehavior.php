<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\LazyCollection;
use Spatie\LaravelData\DataCollection;
use Tarfinlabs\EventMachine\ContextManager;
use Illuminate\Pagination\AbstractPaginator;
use Tarfinlabs\EventMachine\Enums\SourceType;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\PaginatedDataCollection;
use Illuminate\Support\Traits\InteractsWithData;
use Illuminate\Pagination\AbstractCursorPaginator;
use Spatie\LaravelData\Attributes\WithoutValidation;
use Spatie\LaravelData\CursorPaginatedDataCollection;
use Illuminate\Contracts\Pagination\Paginator as PaginatorContract;
use Tarfinlabs\EventMachine\Exceptions\MachineEventValidationException;
use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;

/**
 * Class EventBehavior.
 *
 * Represents the behavior of an event.
 */
abstract class EventBehavior extends Data
{
    /**
     * Use InteractsWithData trait with aliases to avoid conflicts between:
     * - Spatie Data's static collect() vs Laravel trait's non-static collect()
     * - Different return types between parent class and trait methods
     */
    use InteractsWithData {
        InteractsWithData::collect as collection;
        InteractsWithData::only as onlyItems;
        InteractsWithData::except as exceptItems;
    }

    /** Actor performing the event. */
    private mixed $actor = null;

    public bool $isTransactional = true;

    /**
     * Creates a new instance of the class.
     *
     * @param  null|string|Optional  $type  The type of the object. Default is null.
     * @param  null|array|Optional  $payload  The payload to be associated with the object. Default is null.
     * @param  mixed  $actor  Actor performing the event. Default is null.
     * @param  int|Optional  $version  The version number of the object. Default is 1.
     * @param  SourceType  $source  The source type of the object. Default is SourceType::EXTERNAL.
     */
    public function __construct(
        public null|string|Optional $type = null,
        public null|array|Optional $payload = null,
        #[WithoutValidation]
        ?bool $isTransactional = null,
        #[WithoutValidation]
        mixed $actor = null,
        public int|Optional $version = 1,

        #[WithoutValidation]
        public SourceType $source = SourceType::EXTERNAL,
    ) {
        if ($this->type === null) {
            $this->type = static::getType();
        }

        if ($isTransactional !== null) {
            $this->isTransactional = $isTransactional;
        }

        $this->actor = $actor;
    }

    /**
     * Returns the event type string.
     *
     * By default, derives the type from the class name by stripping
     * the 'Event' suffix and converting to SCREAMING_SNAKE_CASE.
     *
     * Override this method only when the auto-generated type doesn't
     * match your needs (e.g., legacy compatibility, external system integration).
     *
     * Examples:
     *   OrderSubmittedEvent    → ORDER_SUBMITTED
     *   IncreaseEvent          → INCREASE
     *   AddAnotherValueEvent   → ADD_ANOTHER_VALUE
     *
     * @return string The event type in SCREAMING_SNAKE_CASE.
     */
    public static function getType(): string
    {
        $baseName = Str::of(static::class)->classBasename();
        $stripped = $baseName->beforeLast('Event');

        return ($stripped->isEmpty() ? $baseName : $stripped)
            ->snake()
            ->upper()
            ->toString();
    }

    /**
     * Validates the object by calling the static validate() method and handles any validation exceptions.
     *
     * @throws MachineEventValidationException If the object fails validation.
     */
    public function selfValidate(): void
    {
        try {
            static::validate($this);
        } catch (ValidationException $e) {
            throw new MachineEventValidationException($e->validator);
        }
    }

    public function actor(ContextManager $context): mixed
    {
        return $this->actor;
    }

    /**
     * Retrieves the scenario value from the payload.
     *
     * @return string|null The scenario value if available, otherwise null.
     */
    public function getScenario(): ?string
    {
        return $this->payload['scenarioType'] ?? null;
    }

    /**
     * Indicates if the validator should stop on the first rule failure.
     *
     * @return bool Returns true by default.
     */
    public static function stopOnFirstFailure(): bool
    {
        return true;
    }

    /**
     * Delegate to parent's static collect() from Spatie Data class.
     * The trait's non-static collect() is aliased as 'collection' to avoid conflict.
     */
    public static function collect(mixed $items, ?string $into = null): array|DataCollection|PaginatedDataCollection|CursorPaginatedDataCollection|Enumerable|AbstractPaginator|PaginatorContract|AbstractCursorPaginator|CursorPaginatorContract|LazyCollection|Collection
    {
        return parent::collect($items, $into);
    }

    /**
     * Override only() to return static type for fluent interface.
     * Uses parent implementation which correctly returns EventBehavior instance.
     */
    public function only(...$args): static
    {
        return parent::only(...$args);
    }

    /**
     * Override except() to return static type for fluent interface.
     * Uses parent implementation which correctly returns EventBehavior instance.
     */
    public function except(...$args): static
    {
        return parent::except(...$args);
    }

    /**
     * Get all of the input and files for the request.
     *
     * @param  array|mixed|null  $keys
     */
    public function all($keys = null): array
    {
        $input = $this->payload ?? [];

        if ($keys === null) {
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
     * @param  string|null  $key
     * @param  mixed  $default
     */
    public function data($key = null, $default = null): mixed
    {
        return data_get($this->all(), $key, $default);
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
}
