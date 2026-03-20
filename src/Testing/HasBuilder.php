<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Testing;

/**
 * Provides builder() discovery for EventBehavior subclasses.
 *
 * Usage: Add `use HasBuilder` to your event class and annotate with
 * `@use HasBuilder<YourEventBuilder>` for IDE autocomplete.
 *
 * @template TBuilder of EventBuilder
 */
trait HasBuilder
{
    /**
     * Create a new builder instance for this event.
     *
     * @return TBuilder
     */
    public static function builder(): EventBuilder
    {
        $builderClass = static::resolveBuilderClass();

        return $builderClass::new();
    }

    /**
     * Resolve the builder class for this event.
     *
     * Default convention: {EventClass}Builder in the same namespace.
     * Override this method when the builder lives in a different namespace.
     *
     * @return class-string<TBuilder>
     */
    protected static function resolveBuilderClass(): string
    {
        $builderClass = static::class.'Builder';

        if (class_exists($builderClass)) {
            return $builderClass;
        }

        throw new \RuntimeException(
            "Builder [{$builderClass}] not found for [".static::class.']. '
            .'Create the builder class or override resolveBuilderClass().'
        );
    }
}
