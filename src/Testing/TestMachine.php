<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Testing;

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Support\Timer;
use PHPUnit\Framework\AssertionFailedError;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Models\MachineTimerFire;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;
use Tarfinlabs\EventMachine\Definition\TimerDefinition;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Definition\TransitionDefinition;
use Tarfinlabs\EventMachine\Exceptions\MachineValidationException;
use Tarfinlabs\EventMachine\Exceptions\NoTransitionDefinitionFoundException;

class TestMachine
{
    private readonly Machine $machine;

    /** @var array<string> */
    private array $fakedBehaviors = [];

    /** @var array<string> Inline behavior keys registered via faking() */
    private array $fakedInlineBehaviors = [];

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
        $definition                = clone $machineClass::definition();
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
     *
     * Supports four formats in a single array:
     *   - Numeric key + FQCN string:   SendEmailAction::class        → class-based spy
     *   - Numeric key + string:         'broadcastAction'             → inline fake (no-op)
     *   - String key + scalar value:    'isValidGuard' => false       → inline fake with return value
     *   - String key + Closure:         'calcTax' => fn(...) => 0     → inline fake with custom replacement
     */
    public function faking(array $behaviors): self
    {
        foreach ($behaviors as $key => $value) {
            if (is_int($key)) {
                // Numeric key: $value is the behavior identifier
                if (is_string($value) && is_subclass_of($value, InvokableBehavior::class)) {
                    // Class-based behavior → existing spy mechanism
                    $value::spy();
                    $this->fakedBehaviors[] = $value;
                } elseif (is_string($value)) {
                    // Inline behavior key → fake with no-op
                    $this->validateInlineBehaviorKey($value);
                    InlineBehaviorFake::fake($value);
                    $this->fakedInlineBehaviors[] = $value;
                }
            } else {
                // String key: inline behavior with specific return value or replacement
                $this->validateInlineBehaviorKey($key);

                if ($value instanceof \Closure) {
                    // Custom replacement closure
                    InlineBehaviorFake::fake($key, $value);
                } else {
                    // Scalar return value (most common: guard true/false)
                    InlineBehaviorFake::shouldReturn($key, $value);
                }

                $this->fakedInlineBehaviors[] = $key;
            }
        }

        return $this;
    }

    /**
     * Validate that an inline behavior key exists in the machine's behavior array.
     *
     * Provides fail-fast typo detection similar to how PHP class loading catches FQCN typos.
     * Only available through TestMachine::faking() (requires machine context).
     *
     * @throws \InvalidArgumentException If the key is not found
     */
    private function validateInlineBehaviorKey(string $key): void
    {
        $allBehaviorKeys = array_merge(
            array_keys($this->machine->definition->behavior['actions'] ?? []),
            array_keys($this->machine->definition->behavior['guards'] ?? []),
            array_keys($this->machine->definition->behavior['calculators'] ?? []),
        );

        if (!in_array($key, $allBehaviorKeys, true)) {
            throw new \InvalidArgumentException(
                "Inline behavior key '{$key}' not found in machine definition. "
                .'Available keys: ['.implode(', ', $allBehaviorKeys).']'
            );
        }
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

            return $this; // Unreachable — expect() throws, but explicit for clarity
        }

        expect($this->machine->state->value)->toBe($before,
            "Expected event to be guarded by [{$guardName}], but a transition occurred"
        );

        $stateDefinition = $this->machine->state->currentStateDefinition;
        expect($stateDefinition)->not->toBeNull('Cannot assertGuardedBy: no current state definition');

        $machineId = $stateDefinition->machine->id;

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

        $position = 0;
        $count    = count($visitedStates);

        foreach ($states as $expectedState) {
            $found = false;
            for ($i = $position; $i < $count; $i++) {
                if ($visitedStates[$i] === $expectedState) {
                    $position = $i + 1;
                    $found    = true;
                    break;
                }
            }
            expect($found)->toBeTrue(
                "Expected state [{$expectedState}] after position {$position} in visited states: [".implode(', ', $visitedStates).']'
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
            ->first(function (mixed $v) use ($regionId): bool {
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
                fn (mixed $type): bool => str_starts_with((string) $type, $prefix) && str_ends_with((string) $type, $suffix)
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

    public function assertBehaviorRan(string $classOrKey): self
    {
        if (is_subclass_of($classOrKey, InvokableBehavior::class)) {
            $classOrKey::assertRan();
        } else {
            InlineBehaviorFake::assertRan($classOrKey);
        }

        return $this;
    }

    public function assertBehaviorNotRan(string $classOrKey): self
    {
        if (is_subclass_of($classOrKey, InvokableBehavior::class)) {
            $classOrKey::assertNotRan();
        } else {
            InlineBehaviorFake::assertNotRan($classOrKey);
        }

        return $this;
    }

    public function assertBehaviorRanTimes(string $classOrKey, int $times): self
    {
        if (is_subclass_of($classOrKey, InvokableBehavior::class)) {
            $classOrKey::assertRanTimes($times);
        } else {
            InlineBehaviorFake::assertRanTimes($classOrKey, $times);
        }

        return $this;
    }

    /**
     * Assert behavior was called with matching arguments.
     *
     * For class-based behaviors: callback receives individual Mockery-recorded args.
     * For inline behaviors: callback receives the full injected parameter array as
     * a single argument (see InlineBehaviorFake::assertRanWith() docblock).
     */
    public function assertBehaviorRanWith(string $classOrKey, \Closure $callback): self
    {
        if (is_subclass_of($classOrKey, InvokableBehavior::class)) {
            $classOrKey::assertRanWith($callback);
        } else {
            InlineBehaviorFake::assertRanWith($classOrKey, $callback);
        }

        return $this;
    }

    // ═══════════════════════════════════════════
    //  Debugging
    // ═══════════════════════════════════════════

    /**
     * Debug guard evaluation results for an event.
     *
     * WARNING: This method SENDS the event — it mutates machine state.
     * If all guards pass, the machine will transition. Use only as a
     * diagnostic tool when state mutation is acceptable.
     *
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
    //  Timer Testing
    // ═══════════════════════════════════════════

    /**
     * Advance time and run the timer sweep for this machine.
     *
     * Persists the machine, backdates state_entered_at by the given duration,
     * runs the sweep logic inline, and refreshes the machine state.
     *
     * @throws \RuntimeException If machine has no persistence (withoutPersistence mode).
     */
    public function advanceTimers(Timer $duration): self
    {
        if ($this->machine->definition->shouldPersist === false) {
            throw new \RuntimeException('advanceTimers() requires persistence. Remove withoutPersistence() to use timer testing.');
        }

        // Persist to sync machine_current_states
        $this->machine->persist();

        $rootEventId = $this->machine->state->history->first()?->root_event_id;

        if ($rootEventId === null) {
            return $this;
        }

        // Backdate state_entered_at
        MachineCurrentState::forInstance($rootEventId)
            ->update(['state_entered_at' => now()->subSeconds($duration->inSeconds())]);

        // Also backdate last_fired_at for @every timers so interval check works
        MachineTimerFire::where('root_event_id', $rootEventId)
            ->where('status', MachineTimerFire::STATUS_ACTIVE)
            ->update(['last_fired_at' => now()->subSeconds($duration->inSeconds())]);

        // Run sweep inline
        $this->processTimers();

        return $this;
    }

    /**
     * Run the timer sweep for this machine class without advancing time.
     *
     * Instead of dispatching jobs via Bus::batch (which requires job_batches table),
     * this method directly sends timer events to the machine — faster and no queue
     * infrastructure needed in tests.
     */
    public function processTimers(): self
    {
        // Persist if needed
        if ($this->machine->state->history->isNotEmpty()) {
            $this->machine->persist();
        }

        $machineClass = $this->machine->definition->machineClass ?? $this->machine::class;
        $rootEventId  = $this->machine->state->history->first()?->root_event_id;

        if ($rootEventId === null) {
            return $this;
        }

        // Collect timer definitions from current machine's definition
        $definition = $machineClass::definition();

        foreach ($definition->idMap as $stateDefinition) {
            if ($stateDefinition->transitionDefinitions === null) {
                continue;
            }

            foreach ($stateDefinition->transitionDefinitions as $transitionDef) {
                if ($transitionDef->timerDefinition === null) {
                    continue;
                }

                $timer    = $transitionDef->timerDefinition;
                $instance = MachineCurrentState::forInstance($rootEventId)
                    ->where('state_id', $timer->stateId)
                    ->first();

                if ($instance === null) {
                    continue;
                }

                if ($timer->isAfter()) {
                    $this->processAfterTimerInline($instance, $timer, $rootEventId);
                } elseif ($timer->isEvery()) {
                    $this->processEveryTimerInline($instance, $timer, $rootEventId);
                }
            }
        }

        // No need to refresh — we used $this->machine->send() directly

        return $this;
    }

    /**
     * Process an after timer inline (no queue, direct send).
     */
    private function processAfterTimerInline(MachineCurrentState $instance, TimerDefinition $timer, string $rootEventId): void
    {
        $deadline = now()->subSeconds($timer->delaySeconds);

        if ($instance->state_entered_at->greaterThan($deadline)) {
            return; // Not past deadline
        }

        // Check dedup
        $alreadyFired = MachineTimerFire::where('root_event_id', $rootEventId)
            ->where('timer_key', $timer->key())
            ->where('status', MachineTimerFire::STATUS_FIRED)
            ->exists();

        if ($alreadyFired) {
            return;
        }

        // Send event directly using THIS machine instance (preserves inline behaviors)
        $this->machine->send(['type' => $timer->eventName]);
        $this->machine->persist();

        // Record fire
        MachineTimerFire::create([
            'root_event_id' => $rootEventId,
            'timer_key'     => $timer->key(),
            'last_fired_at' => now(),
            'fire_count'    => 1,
            'status'        => MachineTimerFire::STATUS_FIRED,
        ]);
    }

    /**
     * Process an every timer inline (no queue, direct send).
     */
    private function processEveryTimerInline(MachineCurrentState $instance, TimerDefinition $timer, string $rootEventId): void
    {
        $lastFire = MachineTimerFire::where('root_event_id', $rootEventId)
            ->where('timer_key', $timer->key())
            ->first();

        if ($lastFire?->isExhausted()) {
            return;
        }

        $lastFiredAt = $lastFire?->last_fired_at ?? $instance->state_entered_at;

        if (now()->diffInSeconds($lastFiredAt, absolute: true) < $timer->delaySeconds) {
            return;
        }

        $currentCount = $lastFire?->fire_count ?? 0;

        // Check max/then
        if ($timer->max !== null && $currentCount >= $timer->max) {
            if ($timer->then !== null) {
                $this->machine->send(['type' => $timer->then]);
                $this->machine->persist();
            }

            MachineTimerFire::updateOrCreate(
                ['root_event_id' => $rootEventId, 'timer_key' => $timer->key()],
                ['status' => MachineTimerFire::STATUS_EXHAUSTED, 'last_fired_at' => now()],
            );

            return;
        }

        // Send event directly using THIS machine instance
        $this->machine->send(['type' => $timer->eventName]);
        $this->machine->persist();

        // Track fire
        if ($lastFire instanceof MachineTimerFire) {
            $lastFire->update([
                'last_fired_at' => now(),
                'fire_count'    => $lastFire->fire_count + 1,
                'status'        => MachineTimerFire::STATUS_ACTIVE,
            ]);
        } else {
            MachineTimerFire::create([
                'root_event_id' => $rootEventId,
                'timer_key'     => $timer->key(),
                'last_fired_at' => now(),
                'fire_count'    => 1,
                'status'        => MachineTimerFire::STATUS_ACTIVE,
            ]);
        }
    }

    /**
     * Assert that the current state has a timer-configured transition for the given event.
     */
    public function assertHasTimer(string $eventName): self
    {
        $currentState = $this->machine->state->currentStateDefinition;
        $transitions  = $currentState->transitionDefinitions ?? [];

        if (!isset($transitions[$eventName])) {
            throw new AssertionFailedError(
                "Expected state '{$currentState->id}' to have a timer for event '{$eventName}', but no transition exists for this event."
                .' Available events: '.implode(', ', array_keys($transitions))
            );
        }

        $timerDef = $transitions[$eventName]->timerDefinition;

        if ($timerDef === null) {
            $timerEvents = collect($transitions)
                ->filter(fn (TransitionDefinition $t): bool => $t->timerDefinition instanceof TimerDefinition)
                ->keys()
                ->implode(', ');

            throw new AssertionFailedError(
                "Expected event '{$eventName}' in state '{$currentState->id}' to have a timer (after/every), but it has none."
                .' Events with timers: '.($timerEvents ?: 'none')
            );
        }

        return $this;
    }

    /**
     * Assert that a timer event has been fired (recorded in machine_timer_fires).
     */
    public function assertTimerFired(string $eventName): self
    {
        $rootEventId = $this->machine->state->history->first()?->root_event_id;

        $fired = MachineTimerFire::where('root_event_id', $rootEventId)
            ->where('timer_key', 'LIKE', "%:{$eventName}:%")
            ->whereIn('status', [
                MachineTimerFire::STATUS_FIRED,
                MachineTimerFire::STATUS_ACTIVE,
                MachineTimerFire::STATUS_EXHAUSTED,
            ])
            ->exists();

        if (!$fired) {
            throw new AssertionFailedError(
                "Expected timer event '{$eventName}' to have been fired, but no matching record found in machine_timer_fires."
            );
        }

        return $this;
    }

    /**
     * Assert that a timer event has NOT been fired.
     */
    public function assertTimerNotFired(string $eventName): self
    {
        $rootEventId = $this->machine->state->history->first()?->root_event_id;

        $fired = MachineTimerFire::where('root_event_id', $rootEventId)
            ->where('timer_key', 'LIKE', "%:{$eventName}:%")
            ->exists();

        if ($fired) {
            throw new AssertionFailedError(
                "Expected timer event '{$eventName}' to NOT have been fired, but a record was found in machine_timer_fires."
            );
        }

        return $this;
    }

    // ═══════════════════════════════════════════
    //  Schedule Helpers
    // ═══════════════════════════════════════════

    /**
     * Simulate running a scheduled event inline (bypasses queue).
     *
     * Sends the event directly to the machine, as if ProcessScheduledCommand
     * had resolved this instance and dispatched it.
     */
    public function runSchedule(string $eventType): self
    {
        $schedules = $this->machine->definition->parsedSchedules ?? [];

        if (!isset($schedules[$eventType])) {
            throw new AssertionFailedError(
                "Schedule '{$eventType}' is not defined on this machine. "
                .'Available schedules: '.(empty($schedules) ? 'none' : implode(', ', array_keys($schedules))).'.'
            );
        }

        return $this->send(['type' => $eventType]);
    }

    /**
     * Assert that the machine definition has a schedule for the given event type.
     */
    public function assertHasSchedule(string $eventType): self
    {
        $schedules = $this->machine->definition->parsedSchedules ?? [];

        if (!isset($schedules[$eventType])) {
            throw new AssertionFailedError(
                "Schedule '{$eventType}' is not defined on this machine. "
                .'Available schedules: '.(empty($schedules) ? 'none' : implode(', ', array_keys($schedules))).'.'
            );
        }

        return $this;
    }

    // ═══════════════════════════════════════════
    //  Available Events
    // ═══════════════════════════════════════════

    /**
     * Assert that the given event type is currently available (sendable).
     */
    public function assertAvailableEvent(string $eventType): self
    {
        $available = $this->machine->state->availableEvents();
        $types     = array_column($available, 'type');

        if (!in_array($eventType, $types, true)) {
            throw new AssertionFailedError(
                "Expected event '{$eventType}' to be available, but it is not. "
                .'Available: '.($types === [] ? 'none' : implode(', ', $types)).'.'
            );
        }

        return $this;
    }

    /**
     * Assert that the given event type is NOT currently available.
     */
    public function assertNotAvailableEvent(string $eventType): self
    {
        $available = $this->machine->state->availableEvents();
        $types     = array_column($available, 'type');

        if (in_array($eventType, $types, true)) {
            throw new AssertionFailedError(
                "Expected event '{$eventType}' to NOT be available, but it is."
            );
        }

        return $this;
    }

    /**
     * Assert the exact set of available event types (order-independent).
     */
    public function assertAvailableEvents(array $expectedTypes): self
    {
        $available   = $this->machine->state->availableEvents();
        $actualTypes = array_column($available, 'type');

        sort($expectedTypes);
        sort($actualTypes);

        if ($expectedTypes !== $actualTypes) {
            throw new AssertionFailedError(
                'Available events mismatch. '
                .'Expected: ['.implode(', ', $expectedTypes).']. '
                .'Actual: ['.implode(', ', $actualTypes).'].'
            );
        }

        return $this;
    }

    /**
     * Assert that a forward event is in available events with source: forward.
     */
    public function assertForwardAvailable(string $eventType): self
    {
        $available = $this->machine->state->availableEvents();

        $found = false;

        foreach ($available as $event) {
            if ($event['type'] === $eventType && $event['source'] === 'forward') {
                $found = true;

                break;
            }
        }

        if (!$found) {
            throw new AssertionFailedError(
                "Expected forward event '{$eventType}' to be available, but it is not."
            );
        }

        return $this;
    }

    /**
     * Assert no events are available (final state, etc.).
     */
    public function assertNoAvailableEvents(): self
    {
        $available = $this->machine->state->availableEvents();

        if ($available !== []) {
            $types = array_column($available, 'type');

            throw new AssertionFailedError(
                'Expected no available events, but found: '.implode(', ', $types).'.'
            );
        }

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

        foreach ($this->fakedInlineBehaviors as $key) {
            InlineBehaviorFake::reset($key);
        }
        $this->fakedInlineBehaviors = [];

        return $this;
    }
}
