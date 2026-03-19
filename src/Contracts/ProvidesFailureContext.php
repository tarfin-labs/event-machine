<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Contracts;

/**
 * Interface for jobs that provide structured failure context.
 *
 * When a job actor implements this interface, its failureContext() output
 * is passed to the parent via ChildMachineFailEvent->output().
 * This enables @fail guards to route based on error codes, categories,
 * or any structured data extracted from the exception.
 *
 * The method is static to avoid re-instantiating the job in the failed() callback.
 *
 * @see ReturnsResult for the success-path equivalent
 */
interface ProvidesFailureContext
{
    /**
     * Extract structured failure context from the exception.
     *
     * This data becomes available via $event->output() in @fail guards/actions.
     * Return keys that your guards will read (e.g., 'errorCode', 'retryable').
     *
     * @return array<string, mixed>
     */
    public static function failureContext(\Throwable $exception): array;
}
