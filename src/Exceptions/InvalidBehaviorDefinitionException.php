<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use LogicException;

/**
 * Thrown when a behavior definition has an invalid tuple format.
 */
class InvalidBehaviorDefinitionException extends LogicException
{
    /**
     * Tuple has no class reference or inline key at position [0].
     */
    public static function missingClassAtZero(string $context): self
    {
        return new self(
            message: "Invalid behavior tuple in {$context} — position [0] must be a class reference or inline key string."
        );
    }

    /**
     * Tuple is empty.
     */
    public static function emptyTuple(string $context): self
    {
        return new self(
            message: "Invalid behavior tuple in {$context} — tuple cannot be empty."
        );
    }

    /**
     * Closure used as [0] in a tuple — closures cannot receive named params.
     */
    public static function closureInTuple(string $context): self
    {
        return new self(
            message: "Invalid behavior tuple in {$context} — closures cannot receive named parameters. Use a class-based behavior instead."
        );
    }

    /**
     * Framework-reserved @-prefixed key (e.g. @queue) used outside its supported context.
     *
     * Currently only `@queue` is supported, and only inside `listen.entry` / `listen.exit` /
     * `listen.transition` config. Using it in state entry/exit actions, transition actions,
     * guards, calculators, or outputs has no effect at runtime — so it is rejected up-front
     * to avoid a silent footgun.
     */
    public static function reservedKeyInTuple(string $context, string $key): self
    {
        $hint = $key === '@queue'
            ? " '@queue' is only supported inside `listen.entry`, `listen.exit`, or `listen.transition`. For async work in state entry actions, use a job actor (`'job' => MyJob::class`), a queued listener (`'listen' => ['entry' => [[MyAction::class, '@queue' => true]]]`), or call dispatch() inside a regular action."
            : '';

        return new self(
            message: "Invalid behavior tuple in {$context} — '{$key}' is a framework-reserved key and is not supported here.{$hint}"
        );
    }
}
