<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Testing;

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\MachineValidationException;
use Tarfinlabs\EventMachine\Exceptions\NoTransitionDefinitionFoundException;

class TestMachine
{
    private readonly Machine $machine;

    /** @var array<string> */
    private array $fakedBehaviors = [];

    private function __construct(Machine $machine)
    {
        $this->machine = $machine;
    }

    // ═══════════════════════════════════════════
    //  Construction
    // ═══════════════════════════════════════════

    /**
     * From a Machine subclass.
     */
    public static function create(string $machineClass, array $context = []): self
    {
        $machine = $machineClass::create();

        foreach ($context as $key => $value) {
            $machine->state->context->set($key, $value);
        }

        return new self($machine);
    }

    /**
     * From an inline definition (no persistence, no Machine class).
     */
    public static function define(array $config, array $behavior = []): self
    {
        $definition                = MachineDefinition::define(config: $config, behavior: $behavior);
        $definition->shouldPersist = false;
        $machine                   = Machine::withDefinition($definition);
        $machine->state            = $definition->getInitialState();

        return new self($machine);
    }

    /**
     * Wrap an existing machine instance.
     */
    public static function for(Machine $machine): self
    {
        return new self($machine);
    }

    // ═══════════════════════════════════════════
    //  Configuration
    // ═══════════════════════════════════════════

    /**
     * Fake specific behaviors. Selective faking à la Bus::fake([...]).
     */
    public function faking(array $behaviors): self
    {
        foreach ($behaviors as $behavior) {
            $behavior::spy();
            $this->fakedBehaviors[] = $behavior;
        }

        return $this;
    }

    /**
     * Disable persistence for this test.
     */
    public function withoutPersistence(): self
    {
        $this->machine->definition->shouldPersist = false;

        return $this;
    }

    /**
     * Disable parallel region dispatch (run regions sequentially).
     */
    public function withoutParallelDispatch(): self
    {
        config(['machine.parallel_dispatch.enabled' => false]);

        return $this;
    }

    // ═══════════════════════════════════════════
    //  Actions
    // ═══════════════════════════════════════════

    /**
     * Send an event. Accepts EventBehavior, array, or string shorthand.
     */
    public function send(EventBehavior|array|string $event): self
    {
        if (is_string($event)) {
            $event = ['type' => $event];
        }

        $this->machine->send($event);

        return $this;
    }

    /**
     * Send multiple events in sequence.
     */
    public function sendMany(array $events): self
    {
        foreach ($events as $event) {
            $this->send($event);
        }

        return $this;
    }

    // ═══════════════════════════════════════════
    //  State Assertions
    // ═══════════════════════════════════════════

    public function assertState(string $expected): self
    {
        $values = $this->machine->state->value;
        $actual = count($values) > 1
            ? "[\n  ".implode(",\n  ", $values)."\n]"
            : '['.implode(', ', $values).']';

        expect($this->machine->state->matches($expected))->toBeTrue(
            "Expected state [{$expected}] but got {$actual}"
        );

        return $this;
    }

    public function assertNotState(string $state): self
    {
        expect($this->machine->state->matches($state))->toBeFalse(
            "Expected NOT to be in state [{$state}]"
        );

        return $this;
    }

    public function assertFinished(): self
    {
        expect($this->machine->state->currentStateDefinition?->type)
            ->toBe(StateDefinitionType::FINAL, 'Expected a final state');

        return $this;
    }

    // ═══════════════════════════════════════════
    //  Context Assertions
    // ═══════════════════════════════════════════

    public function assertContext(string $key, mixed $expected): self
    {
        expect($this->machine->state->context->get($key))->toBe($expected,
            "context[{$key}]: expected ".json_encode($expected)
        );

        return $this;
    }

    public function assertContextMatches(string $key, callable $callback): self
    {
        $value = $this->machine->state->context->get($key);
        expect($callback($value))->toBeTrue(
            "context[{$key}]: value ".json_encode($value).' did not match callback'
        );

        return $this;
    }

    public function assertContextHas(string $key): self
    {
        expect($this->machine->state->context->has($key))->toBeTrue(
            "Expected context to have [{$key}]"
        );

        return $this;
    }

    public function assertContextMissing(string $key): self
    {
        expect($this->machine->state->context->has($key))->toBeFalse(
            "Expected context NOT to have [{$key}]"
        );

        return $this;
    }

    public function assertContextIncludes(array $subset): self
    {
        foreach ($subset as $key => $value) {
            $this->assertContext($key, $value);
        }

        return $this;
    }

    // ═══════════════════════════════════════════
    //  Transition Assertions
    // ═══════════════════════════════════════════

    /**
     * Send + assertState in one call.
     */
    public function assertTransition(array|string $event, string $expectedState): self
    {
        return $this->send($event)->assertState($expectedState);
    }

    /**
     * Assert an event is guarded (state unchanged).
     */
    public function assertGuarded(array|string $event): self
    {
        $before = $this->machine->state->value;

        try {
            $this->send($event);
        } catch (NoTransitionDefinitionFoundException) {
            // Unknown events are inherently guarded — no transition possible
            return $this;
        }

        expect($this->machine->state->value)->toBe($before,
            'Expected event to be guarded, but a transition occurred'
        );

        return $this;
    }

    /**
     * Assert an event raises MachineValidationException.
     */
    public function assertValidationFailed(array|string $event, ?string $errorKey = null): self
    {
        try {
            $this->send($event);
            expect(false)->toBeTrue('Expected MachineValidationException but no exception was thrown');
        } catch (MachineValidationException $e) {
            if ($errorKey !== null) {
                expect($e->errors())->toHaveKey($errorKey);
            }
        } catch (\Throwable $e) {
            expect(false)->toBeTrue(
                'Expected MachineValidationException, got '.$e::class.': '.$e->getMessage()
            );
        }

        return $this;
    }

    // ═══════════════════════════════════════════
    //  History Assertions
    // ═══════════════════════════════════════════

    public function assertHistoryContains(string ...$eventTypes): self
    {
        $history = $this->machine->state->history->pluck('type')->toArray();
        foreach ($eventTypes as $type) {
            expect($history)->toContain($type);
        }

        return $this;
    }

    public function assertHistoryOrder(string ...$eventTypes): self
    {
        $history  = $this->machine->state->history->pluck('type')->toArray();
        $position = 0;

        foreach ($eventTypes as $type) {
            $found   = false;
            $counter = count($history);
            for ($i = $position; $i < $counter; $i++) {
                if ($history[$i] === $type) {
                    $position = $i + 1;
                    $found    = true;
                    break;
                }
            }
            expect($found)->toBeTrue(
                "Expected event [{$type}] after position {$position} in history [".implode(', ', $history).']'
            );
        }

        return $this;
    }

    // ═══════════════════════════════════════════
    //  Path Assertions
    // ═══════════════════════════════════════════

    /**
     * @param  array<array{event: string|array, state: string, context?: array}>  $steps
     */
    public function assertPath(array $steps): self
    {
        foreach ($steps as $i => $step) {
            $this->send($step['event']);
            expect($this->machine->state->matches($step['state']))->toBeTrue(
                "Step {$i}: expected [{$step['state']}], got [".implode(', ', $this->machine->state->value).']'
            );
            foreach ($step['context'] ?? [] as $key => $value) {
                expect($this->machine->state->context->get($key))->toBe($value,
                    "Step {$i}: context[{$key}]"
                );
            }
        }

        return $this;
    }

    // ═══════════════════════════════════════════
    //  Parallel Assertions
    // ═══════════════════════════════════════════

    public function assertRegionState(string $regionId, string $expectedState): self
    {
        $match = collect($this->machine->state->value)
            ->first(function ($v) use ($regionId): bool {
                $segments = explode('.', $v);

                return in_array($regionId, $segments, true);
            });
        expect($match)->not->toBeNull("No active state in region [{$regionId}]");

        $segments  = explode('.', (string) $match);
        $lastState = end($segments);
        expect($lastState)->toBe($expectedState, "Expected state [{$expectedState}] in region [{$regionId}], got [{$lastState}]");

        return $this;
    }

    // ═══════════════════════════════════════════
    //  Behavior Assertions (convenience)
    // ═══════════════════════════════════════════

    public function assertBehaviorRan(string $class): self
    {
        $class::assertRan();

        return $this;
    }

    public function assertBehaviorNotRan(string $class): self
    {
        $class::assertNotRan();

        return $this;
    }

    // ═══════════════════════════════════════════
    //  Accessors
    // ═══════════════════════════════════════════

    public function machine(): Machine
    {
        return $this->machine;
    }

    public function state(): State
    {
        return $this->machine->state;
    }

    public function context(): ContextManager
    {
        return $this->machine->state->context;
    }

    // ═══════════════════════════════════════════
    //  Cleanup
    // ═══════════════════════════════════════════

    public function resetFakes(): self
    {
        foreach ($this->fakedBehaviors as $behavior) {
            $behavior::resetFakes();
        }

        $this->fakedBehaviors = [];

        return $this;
    }
}
