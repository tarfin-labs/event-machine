<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Testing;

use Illuminate\Support\Arr;
use PHPUnit\Framework\Assert;
use Spatie\LaravelData\Optional;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;

/**
 * Fluent assertions on a raised event matched by InvokableBehavior::assertRaised().
 *
 * All payload keys are interpreted as dot-notation paths (Arr::get/Arr::has
 * semantics) — payload keys containing a literal dot cannot be asserted on.
 */
class RaisedEventAssertion
{
    /**
     * @param  EventBehavior|array<string, mixed>  $event  The first raised event matching the assertion.
     */
    public function __construct(
        protected readonly EventBehavior|array $event,
        protected readonly string $behaviorClass,
        protected readonly string $eventTypeOrClass,
    ) {}

    /**
     * Assert each key exists in the raised event's payload with a strictly
     * equal (===) value. Array values compare the full array strictly — use
     * dot-notation keys to assert nested keys individually.
     *
     * @param  array<string, mixed>  $subset
     */
    public function withPayload(array $subset): self
    {
        if ($subset === []) {
            throw new \InvalidArgumentException(
                'withPayload() requires a non-empty subset — an empty subset asserts nothing.'
            );
        }

        $payload = $this->payload();

        foreach ($subset as $key => $expectedValue) {
            Assert::assertTrue(
                Arr::has($payload, $key),
                "Event '{$this->eventTypeOrClass}' raised by {$this->behaviorClass} is missing payload key [{$key}]."
            );

            Assert::assertSame(
                $expectedValue,
                Arr::get($payload, $key),
                "Event '{$this->eventTypeOrClass}' raised by {$this->behaviorClass} has an unexpected value for payload key [{$key}]."
            );
        }

        return $this;
    }

    /**
     * Assert the payload does NOT contain the given dot-notation key.
     */
    public function withoutPayloadKey(string $key): self
    {
        Assert::assertFalse(
            Arr::has($this->payload(), $key),
            "Event '{$this->eventTypeOrClass}' raised by {$this->behaviorClass} unexpectedly contains payload key [{$key}]."
        );

        return $this;
    }

    /**
     * Run the raised event's own validation rules and fail the test if they throw.
     */
    public function validated(): self
    {
        if (!$this->event instanceof EventBehavior) {
            Assert::fail(
                "Raised event '{$this->eventTypeOrClass}' is a plain array; validated() requires an EventBehavior instance."
            );
        }

        try {
            $this->event->selfValidate();
        } catch (\Throwable $exception) {
            Assert::fail(
                "Raised event '{$this->eventTypeOrClass}' failed self-validation: {$exception->getMessage()}"
            );
        }

        return $this;
    }

    /**
     * Payload of the matched event. Null or Optional payloads are treated as empty arrays.
     *
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        $payload = $this->event instanceof EventBehavior
            ? $this->event->payload
            : ($this->event['payload'] ?? []);

        if ($payload === null || $payload instanceof Optional) {
            return [];
        }

        return $payload;
    }
}
