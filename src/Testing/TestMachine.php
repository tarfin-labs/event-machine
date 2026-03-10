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
     *
     * Context values are applied after machine initialization, so entry actions
     * on the initial state run with the machine's default context — not these
     * overrides.
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
     * From a Machine subclass with pre-start context injection.
     *
     * Unlike create(), context values are merged BEFORE initialization,
     * so entry actions on the initial state see the injected context.
     */
    public static function withContext(string $machineClass, array $context): self
    {
        /** @var MachineDefinition $definition */
        $definition                = $machineClass::definition();
        $definition->shouldPersist = false;
        $definition->machineClass  = $machineClass;

        // Merge context before getInitialState() so entry actions see it
        $definition->config['context'] = array_merge(
            $definition->config['context'] ?? [],
            $context,
        );

        $machine        = Machine::withDefinition($definition);
        $machine->state = $definition->getInitialState();

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
     * Set the scenario type for scenario-aware machines.
     *
     * Sets the 'scenarioType' context key which MachineDefinition::getScenarioStateIfAvailable()
     * reads to route to scenario-specific states.
     */
    public function withScenario(string $scenarioName): self
    {
        $this->machine->state->context->set('scenarioType', $scenarioName);

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
        $actual = '['.implode(', ', $this->machine->state->value).']';
        expect($this->machine->state->matches($state))->toBeFalse(
            "Expected NOT to be in state [{$state}], but machine is in {$actual}"
        );

        return $this;
    }

    public function assertFinished(): self
    {
        expect($this->machine->state->currentStateDefinition?->type)
            ->toBe(StateDefinitionType::FINAL, 'Expected a final state');

        return $this;
    }

    public function assertResult(mixed $expected): self
    {
        expect($this->machine->result())->toBe($expected,
            'Machine result did not match expected value'
        );

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
    public function assertTransition(EventBehavior|array|string $event, string $expectedState): self
    {
        return $this->send($event)->assertState($expectedState);
    }

    /**
     * Assert an event is guarded (state unchanged after send).
     *
     * Note: Cannot detect self-transitions (target === source) since the state
     * value array is identical before and after. Use assertTransition() with
     * explicit state checks for self-transition scenarios.
     */
    public function assertGuarded(EventBehavior|array|string $event): self
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
     * Assert an event is guarded by a specific guard.
     *
     * Sends the event, verifies state did not change, and checks the guard's
     * fail event appears in history. Handles both inline guard keys (camelCase)
     * and FQCN guards (uses classBasename).
     */
    public function assertGuardedBy(EventBehavior|array|string $event, string $guardName): self
    {
        $before = $this->machine->state->value;

        try {
            $this->send($event);
        } catch (NoTransitionDefinitionFoundException) {
            expect(false)->toBeTrue(
                "Event not recognized — cannot verify guard [{$guardName}]"
            );
        }

        expect($this->machine->state->value)->toBe($before,
            "Expected event to be guarded by [{$guardName}], but a transition occurred"
        );

        $machineId = $this->machine->state->currentStateDefinition->machine->id;

        // Derive the placeholder: inline keys are used as-is, FQCN uses classBasename
        $placeholder = class_exists($guardName)
            ? class_basename($guardName)
            : $guardName;

        $guardFailEvent = "{$machineId}.guard.{$placeholder}.fail";
        $guardFailed    = $this->machine->state->history->pluck('type')->contains($guardFailEvent);

        expect($guardFailed)->toBeTrue(
            "Expected guard [{$guardName}] to block the event, but no fail event [{$guardFailEvent}] found in history"
        );

        return $this;
    }

    /**
     * Assert an event raises MachineValidationException.
     */
    public function assertValidationFailed(EventBehavior|array|string $event, ?string $errorKey = null): self
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
            expect(in_array($type, $history, true))->toBeTrue(
                "Expected history to contain [{$type}], got [".implode(', ', $history).']'
            );
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
    //  Transition Path Assertions
    // ═══════════════════════════════════════════

    /**
     * Assert that the machine transitioned through the given states in order.
     *
     * Checks history's machine_value arrays for the expected state names.
     * Useful for verifying @always router states that appear briefly in history.
     *
     * @param  array<string>  $states  Expected states in order
     */
    public function assertTransitionedThrough(array $states): self
    {
        $visitedStates = [];

        foreach ($this->machine->state->history as $event) {
            if (isset($event->machine_value)) {
                foreach ($event->machine_value as $stateValue) {
                    $segments = explode('.', (string) $stateValue);
                    foreach ($segments as $segment) {
                        if (!in_array($segment, $visitedStates, true)) {
                            $visitedStates[] = $segment;
                        }
                    }
                }
            }
        }

        foreach ($states as $expectedState) {
            expect(in_array($expectedState, $visitedStates, true))->toBeTrue(
                "Expected machine to have transitioned through [{$expectedState}], visited states: [".implode(', ', $visitedStates).']'
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

    /**
     * Assert that all regions in a parallel state completed (@done fired).
     *
     * Checks history for the PARALLEL_DONE internal event. For machines with
     * a single parallel state, no argument is needed. For machines with multiple
     * parallel states, pass the parallel state route to disambiguate.
     */
    public function assertAllRegionsCompleted(?string $parallelStateRoute = null): self
    {
        $machineId = $this->machine->definition->id;
        $history   = $this->machine->state->history->pluck('type');

        if ($parallelStateRoute !== null) {
            $expectedEvent = "{$machineId}.parallel.{$parallelStateRoute}.done";
            expect($history->contains($expectedEvent))->toBeTrue(
                "Expected all regions of [{$parallelStateRoute}] to complete, but PARALLEL_DONE event [{$expectedEvent}] not found in history"
            );
        } else {
            $prefix = "{$machineId}.parallel.";
            $suffix = '.done';
            $found  = $history->contains(
                fn ($type): bool => str_starts_with((string) $type, $prefix) && str_ends_with((string) $type, $suffix)
            );

            expect($found)->toBeTrue(
                'Expected all regions to complete, but no PARALLEL_DONE event found in history'
            );
        }

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

    public function assertBehaviorRanTimes(string $class, int $times): self
    {
        $class::assertRanTimes($times);

        return $this;
    }

    public function assertBehaviorRanWith(string $class, \Closure $callback): self
    {
        $class::assertRanWith($callback);

        return $this;
    }

    // ═══════════════════════════════════════════
    //  Debugging
    // ═══════════════════════════════════════════

    /**
     * Debug guard evaluation results for an event.
     *
     * Sends the event and inspects history for guard pass/fail events.
     * Returns an associative array of guard names => bool (true = passed, false = failed).
     *
     * @return array<string, bool>
     */
    public function debugGuards(EventBehavior|array|string $event): array
    {
        $historyCountBefore = count($this->machine->state->history);

        try {
            $this->send($event);
        } catch (NoTransitionDefinitionFoundException) {
            return [];
        }

        $machineId = $this->machine->state->currentStateDefinition->machine->id;
        $results   = [];

        $newEvents = $this->machine->state->history->slice($historyCountBefore);

        foreach ($newEvents as $historyEvent) {
            if (preg_match("/{$machineId}\.guard\.(.+)\.(pass|fail)/", $historyEvent->type, $matches)) {
                $results[$matches[1]] = $matches[2] === 'pass';
            }
        }

        return $results;
    }

    // ═══════════════════════════════════════════
    //  Utilities
    // ═══════════════════════════════════════════

    /**
     * Execute a callback for side-effect assertions (e.g. Notification::assertSentTo)
     * while maintaining the fluent chain.
     */
    public function tap(callable $callback): self
    {
        $callback($this);

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

    /**
     * Reset only the behaviors registered via faking().
     *
     * Behaviors faked directly (e.g. SomeBehavior::fake()) outside of faking()
     * are NOT cleaned up by this method. Use SomeBehavior::resetFakes() or
     * InvokableBehavior::resetAllFakes() for those.
     */
    public function resetFakes(): self
    {
        foreach ($this->fakedBehaviors as $behavior) {
            $behavior::resetFakes();
        }

        $this->fakedBehaviors = [];

        return $this;
    }
}
