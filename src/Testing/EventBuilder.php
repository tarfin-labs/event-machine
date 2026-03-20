<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Testing;

use Closure;
use Faker\Generator as Faker;
use Illuminate\Support\Facades\App;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;

/**
 * @phpstan-consistent-constructor
 */
abstract class EventBuilder
{
    protected Faker $faker;

    /** @var array<int, Closure|array> */
    private array $states = [];

    public function __construct()
    {
        $this->faker = App::make(Faker::class);
    }

    /**
     * The EventBehavior class this builder produces.
     *
     * @return class-string<EventBehavior>
     */
    abstract protected function eventClass(): string;

    /**
     * Default attributes — equivalent to Factory::definition().
     *
     * Override this when you need faker-generated realistic defaults.
     * The base implementation provides sensible defaults (type from event class,
     * empty payload, version 1) — same as forTesting().
     *
     * @return array{type: string, payload: array, version: int}
     */
    protected function definition(): array
    {
        $eventClass = $this->eventClass();

        return [
            'type'    => $eventClass::getType(),
            'payload' => [],
            'version' => 1,
        ];
    }

    /**
     * Static constructor — equivalent to Factory::new().
     */
    public static function new(): static
    {
        return new static();
    }

    /**
     * Add a state mutation — equivalent to Factory::state().
     * Returns a clone for immutable chaining.
     */
    public function state(Closure|array $state): static
    {
        $clone           = clone $this;
        $clone->states[] = $state;

        return $clone;
    }

    /**
     * Build the EventBehavior instance — equivalent to Factory::make().
     */
    public function make(array $attributes = []): EventBehavior
    {
        $raw        = $this->raw($attributes);
        $eventClass = $this->eventClass();

        return $eventClass::from($raw);
    }

    /**
     * Get the raw attribute array — equivalent to Factory::raw().
     * Useful for validation testing with validateAndCreate().
     */
    public function raw(array $attributes = []): array
    {
        $definition = $this->definition();

        foreach ($this->states as $state) {
            $definition = $state instanceof Closure
                ? $state($definition)
                : array_replace_recursive($definition, $state);
        }

        return array_replace_recursive($definition, $attributes);
    }
}
