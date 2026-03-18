<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Testing;

use Carbon\Carbon;
use PHPUnit\Framework\Assert;
use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Support\Timer;
use PHPUnit\Framework\AssertionFailedError;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Enums\SourceType;
use Tarfinlabs\EventMachine\Enums\InternalEvent;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Models\MachineTimerFire;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Definition\TimerDefinition;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Behavior\ChildMachineDoneEvent;
use Tarfinlabs\EventMachine\Behavior\ChildMachineFailEvent;
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

    /** @var array<string> Child machine classes registered via fakingChild() */
    private array $fakedChildMachines = [];

    /** @var Carbon|null When the current state was entered (in-memory timer mode) */
    private ?Carbon $inMemoryStateEnteredAt = null;

    /** @var array<string, array{last_fired_at: Carbon, fire_count: int, status: string}> In-memory timer fire records */
    private array $inMemoryTimerFires = [];

    /** @var string|null Last tracked state ID for detecting transitions (in-memory timer mode) */
    private ?string $lastTrackedStateId = null;

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

        $instance = new self($machine);
        $instance->trackStateEntry();

        return $instance;
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

        $instance = new self($machine);
        $instance->trackStateEntry();

        return $instance;
    }

    /**
     * Wrap an existing machine instance.
     */
    public static function for(Machine $machine): self
    {
        $instance = new self($machine);

        if ($machine->definition->shouldPersist === false) {
            $instance->trackStateEntry();
        }

        return $instance;
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

        // Initialize in-memory timer state tracking
        $this->trackStateEntry();

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

        if ($this->machine->definition->shouldPersist === false) {
            $this->trackStateEntry();
        }

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

        Assert::assertTrue(
            $this->machine->state->matches($expected),
            "Expected state [{$expected}] but got {$actual}"
        );

        return $this;
    }

    public function assertNotState(string $state): self
    {
        $actual = '['.implode(', ', $this->machine->state->value).']';
        Assert::assertFalse(
            $this->machine->state->matches($state),
            "Expected NOT to be in state [{$state}], but machine is in {$actual}"
        );

        return $this;
    }

    public function assertFinished(): self
    {
        Assert::assertSame(
            StateDefinitionType::FINAL,
            $this->machine->state->currentStateDefinition?->type,
            'Expected a final state'
        );

        return $this;
    }

    public function assertResult(mixed $expected): self
    {
        Assert::assertSame(
            $expected,
            $this->machine->result(),
            'Machine result did not match expected value'
        );

        return $this;
    }

    // ═══════════════════════════════════════════
    //  Context Assertions
    // ═══════════════════════════════════════════

    public function assertContext(string $key, mixed $expected): self
    {
        Assert::assertSame(
            $expected,
            $this->machine->state->context->get($key),
            "context[{$key}]: expected ".json_encode($expected)
        );

        return $this;
    }

    public function assertContextMatches(string $key, callable $callback): self
    {
        $value = $this->machine->state->context->get($key);
        Assert::assertTrue(
            $callback($value),
            "context[{$key}]: value ".json_encode($value).' did not match callback'
        );

        return $this;
    }

    public function assertContextHas(string $key): self
    {
        Assert::assertTrue(
            $this->machine->state->context->has($key),
            "Expected context to have [{$key}]"
        );

        return $this;
    }

    public function assertContextMissing(string $key): self
    {
        Assert::assertFalse(
            $this->machine->state->context->has($key),
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

        Assert::assertSame(
            $before,
            $this->machine->state->value,
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
            Assert::fail("Event not recognized — cannot verify guard [{$guardName}]");
        }

        Assert::assertSame(
            $before,
            $this->machine->state->value,
            "Expected event to be guarded by [{$guardName}], but a transition occurred"
        );

        $stateDefinition = $this->machine->state->currentStateDefinition;
        Assert::assertNotNull($stateDefinition, 'Cannot assertGuardedBy: no current state definition');

        $machineId = $stateDefinition->machine->id;

        // Derive the placeholder: inline keys are used as-is, FQCN uses classBasename
        $placeholder = class_exists($guardName)
            ? class_basename($guardName)
            : $guardName;

        $guardFailEvent = "{$machineId}.guard.{$placeholder}.fail";
        $guardFailed    = $this->machine->state->history->pluck('type')->contains($guardFailEvent);

        Assert::assertTrue(
            $guardFailed,
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
            Assert::fail('Expected MachineValidationException but no exception was thrown');
        } catch (MachineValidationException $e) {
            if ($errorKey !== null) {
                Assert::assertArrayHasKey($errorKey, $e->errors());
            }
        } catch (AssertionFailedError $e) {
            throw $e;
        } catch (\Throwable $e) {
            Assert::fail(
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
            Assert::assertTrue(
                in_array($type, $history, true),
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
            Assert::assertTrue(
                $found,
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
            Assert::assertTrue(
                $found,
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
            Assert::assertTrue(
                $this->machine->state->matches($step['state']),
                "Step {$i}: expected [{$step['state']}], got [".implode(', ', $this->machine->state->value).']'
            );
            foreach ($step['context'] ?? [] as $key => $value) {
                Assert::assertSame(
                    $value,
                    $this->machine->state->context->get($key),
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
        Assert::assertNotNull($match, "No active state in region [{$regionId}]");

        $segments  = explode('.', (string) $match);
        $lastState = end($segments);
        Assert::assertSame(
            $expectedState,
            $lastState,
            "Expected state [{$expectedState}] in region [{$regionId}], got [{$lastState}]"
        );

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
            Assert::assertTrue(
                $history->contains($expectedEvent),
                "Expected all regions of [{$parallelStateRoute}] to complete, but PARALLEL_DONE event [{$expectedEvent}] not found in history"
            );
        } else {
            $prefix = "{$machineId}.parallel.";
            $suffix = '.done';
            $found  = $history->contains(
                fn (mixed $type): bool => str_starts_with((string) $type, $prefix) && str_ends_with((string) $type, $suffix)
            );

            Assert::assertTrue(
                $found,
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
     */
    public function advanceTimers(Timer $duration): self
    {
        if ($this->machine->definition->shouldPersist === false) {
            return $this->advanceTimersInMemory($duration);
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
     * Track state entry for in-memory timer mode.
     *
     * Called after any operation that may change state (send, simulateChild*, etc.)
     * when persistence is off. Records the entry timestamp and cleans up old-state
     * timer fires — active recurring fires are removed, but historical records
     * (fired/exhausted) are preserved for assertions.
     */
    private function trackStateEntry(): void
    {
        $currentId = $this->machine->state->currentStateDefinition->id;

        if ($currentId !== $this->lastTrackedStateId) {
            $this->inMemoryStateEnteredAt = now();
            $this->lastTrackedStateId     = $currentId;

            // Keep: all fires for current state (any status)
            // Keep: historical fires for old states (fired/exhausted) — for assertions
            // Remove: active recurring fires for old states — stop @every timers
            $this->inMemoryTimerFires = array_filter(
                $this->inMemoryTimerFires,
                fn (array $fire, string $key): bool => str_starts_with($key, $currentId.':')
                    || $fire['status'] !== 'active',
                ARRAY_FILTER_USE_BOTH,
            );
        }
    }

    /**
     * Assert that the current state has a timer-configured transition for the given event.
     * Optionally verify the timer's duration matches.
     */
    public function assertHasTimer(string $eventName, ?Timer $expectedDuration = null): self
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

        if ($expectedDuration instanceof Timer) {
            Assert::assertSame(
                $expectedDuration->inSeconds(),
                $timerDef->delaySeconds,
                "Timer '{$eventName}' has {$timerDef->delaySeconds}s delay, expected {$expectedDuration->inSeconds()}s."
            );
        }

        return $this;
    }

    /**
     * Assert that a timer event has been fired (recorded in machine_timer_fires or in-memory).
     */
    public function assertTimerFired(string $eventName): self
    {
        if ($this->machine->definition->shouldPersist === false) {
            return $this->assertTimerFiredInMemory($eventName);
        }

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
        if ($this->machine->definition->shouldPersist === false) {
            return $this->assertTimerNotFiredInMemory($eventName);
        }

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

    private function assertTimerFiredInMemory(string $eventName): self
    {
        foreach (array_keys($this->inMemoryTimerFires) as $key) {
            if (str_contains($key, ":{$eventName}:")) {
                return $this;
            }
        }

        throw new AssertionFailedError(
            "Expected timer event '{$eventName}' to have been fired (in-memory), but no record found."
        );
    }

    private function assertTimerNotFiredInMemory(string $eventName): self
    {
        foreach (array_keys($this->inMemoryTimerFires) as $key) {
            if (str_contains($key, ":{$eventName}:")) {
                throw new AssertionFailedError(
                    "Expected timer event '{$eventName}' to NOT have been fired (in-memory), but a record was found."
                );
            }
        }

        return $this;
    }

    // ═══════════════════════════════════════════
    //  In-Memory Timer Processing
    // ═══════════════════════════════════════════

    /**
     * Advance timers without database persistence.
     *
     * Backdates the in-memory state entry time and active timer fires,
     * then runs the timer sweep in-memory.
     */
    private function advanceTimersInMemory(Timer $duration): self
    {
        if (!$this->inMemoryStateEnteredAt instanceof Carbon) {
            return $this;
        }

        // Simulate time passing (copy() avoids Carbon mutability issues)
        $this->inMemoryStateEnteredAt = $this->inMemoryStateEnteredAt
            ->copy()->subSeconds($duration->inSeconds());

        // Also backdate in-memory timer fires for @every
        foreach ($this->inMemoryTimerFires as &$fire) {
            if ($fire['status'] === 'active') {
                $fire['last_fired_at'] = $fire['last_fired_at']
                    ->copy()->subSeconds($duration->inSeconds());
            }
        }
        unset($fire);

        // Run sweep in-memory
        $this->processTimersInMemory();

        return $this;
    }

    /**
     * Run the timer sweep in-memory for the current state.
     *
     * Snapshots the current state's transitions before iterating so that
     * mid-loop state changes (from timer fires) don't affect the sweep.
     */
    private function processTimersInMemory(): self
    {
        $currentState = $this->machine->state->currentStateDefinition;

        foreach ($currentState->transitionDefinitions ?? [] as $transitionDef) {
            if ($transitionDef->timerDefinition === null) {
                continue;
            }

            $timer = $transitionDef->timerDefinition;

            if ($timer->isAfter()) {
                $this->processAfterTimerInMemory($timer);
            } elseif ($timer->isEvery()) {
                $this->processEveryTimerInMemory($timer);
            }
        }

        return $this;
    }

    /**
     * Process an @after timer in-memory (one-shot, with dedup).
     */
    private function processAfterTimerInMemory(TimerDefinition $timer): void
    {
        $deadline = now()->subSeconds($timer->delaySeconds);

        if ($this->inMemoryStateEnteredAt->greaterThan($deadline)) {
            return; // Not past deadline
        }

        $key = $timer->key();

        // Check dedup — already fired?
        if (isset($this->inMemoryTimerFires[$key])
            && $this->inMemoryTimerFires[$key]['status'] === 'fired') {
            return;
        }

        // Send event
        $this->machine->send(['type' => $timer->eventName]);

        // Record fire (persists across state transitions for assertions)
        $this->inMemoryTimerFires[$key] = [
            'last_fired_at' => now(),
            'fire_count'    => 1,
            'status'        => 'fired',
        ];

        // Track state entry if transition happened
        $this->trackStateEntry();
    }

    /**
     * Process an @every timer in-memory (recurring, with max/then support).
     */
    private function processEveryTimerInMemory(TimerDefinition $timer): void
    {
        $key      = $timer->key();
        $fireData = $this->inMemoryTimerFires[$key] ?? null;

        // Check exhausted
        if ($fireData !== null && $fireData['status'] === 'exhausted') {
            return;
        }

        $lastFiredAt  = $fireData['last_fired_at'] ?? $this->inMemoryStateEnteredAt;
        $currentCount = $fireData['fire_count'] ?? 0;

        if (now()->diffInSeconds($lastFiredAt, absolute: true) < $timer->delaySeconds) {
            return;
        }

        // Check max/then
        if ($timer->max !== null && $currentCount >= $timer->max) {
            if ($timer->then !== null) {
                $this->machine->send(['type' => $timer->then]);
                $this->trackStateEntry();
            }

            $this->inMemoryTimerFires[$key] = [
                'last_fired_at' => now(),
                'fire_count'    => $currentCount,
                'status'        => 'exhausted',
            ];

            return;
        }

        // Send event
        $this->machine->send(['type' => $timer->eventName]);

        // Track fire
        $this->inMemoryTimerFires[$key] = [
            'last_fired_at' => now(),
            'fire_count'    => $currentCount + 1,
            'status'        => 'active',
        ];

        $this->trackStateEntry();
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
    //  Child Delegation (v2)
    // ═══════════════════════════════════════════

    /**
     * Fake a child machine within the fluent chain.
     *
     * Tracked for selective cleanup in resetFakes().
     */
    public function fakingChild(
        string $childClass,
        ?array $result = null,
        bool $fail = false,
        ?string $error = null,
        ?string $finalState = null,
    ): self {
        $childClass::fake(result: $result, fail: $fail, error: $error, finalState: $finalState);
        $this->fakedChildMachines[] = $childClass;

        return $this;
    }

    /**
     * Assert that a child machine class was invoked at least once.
     */
    public function assertChildInvoked(string $childClass): self
    {
        $invocations = Machine::getMachineInvocations($childClass);

        if ($invocations === []) {
            throw new AssertionFailedError(
                "Expected child machine [{$childClass}] to be invoked, but it was not."
            );
        }

        return $this;
    }

    /**
     * Assert that a child machine class was NOT invoked.
     */
    public function assertChildNotInvoked(string $childClass): self
    {
        $invocations = Machine::getMachineInvocations($childClass);

        if ($invocations !== []) {
            throw new AssertionFailedError(
                "Expected child machine [{$childClass}] not to be invoked, but it was invoked ".count($invocations).' time(s).'
            );
        }

        return $this;
    }

    /**
     * Assert exact invocation count for a child machine.
     */
    public function assertChildInvokedTimes(string $childClass, int $times): self
    {
        $invocations = Machine::getMachineInvocations($childClass);
        $actual      = count($invocations);

        if ($actual !== $times) {
            throw new AssertionFailedError(
                "Expected child machine [{$childClass}] to be invoked {$times} time(s), but was invoked {$actual} time(s)."
            );
        }

        return $this;
    }

    /**
     * Assert a child machine was invoked with context containing the expected subset.
     */
    public function assertChildInvokedWith(string $childClass, array $expectedContext): self
    {
        $invocations = Machine::getMachineInvocations($childClass);

        if ($invocations === []) {
            throw new AssertionFailedError(
                "Expected child machine [{$childClass}] to be invoked with ".json_encode($expectedContext).', but it was never invoked.'
            );
        }

        foreach ($invocations as $context) {
            $matched = true;

            foreach ($expectedContext as $key => $value) {
                if (!array_key_exists($key, $context) || $context[$key] !== $value) {
                    $matched = false;

                    break;
                }
            }

            if ($matched) {
                return $this;
            }
        }

        throw new AssertionFailedError(
            "Expected child machine [{$childClass}] to be invoked with ".json_encode($expectedContext).', but no invocation matched.'
        );
    }

    /**
     * Assert which @done.{state} route was taken during child completion.
     *
     * Returns the final state key when a specific @done.{state} matched,
     * null when the catch-all @done fired.
     */
    public function assertRoutedViaDoneState(string $expectedFinalState): self
    {
        $actual = $this->machine->state->lastChildDoneRoute;

        if ($actual !== $expectedFinalState) {
            throw new AssertionFailedError(
                "Expected child completion to route via @done.{$expectedFinalState}, "
                .'but '.($actual === null ? '@done catch-all was used' : "@done.{$actual} was used").'.'
            );
        }

        return $this;
    }

    // ═══════════════════════════════════════════
    //  Async Simulation (v2)
    // ═══════════════════════════════════════════

    /**
     * Simulate async child completion without real queues.
     *
     * The `result` parameter populates both `output()` and `result()` accessors,
     * matching Machine::fake() behavior.
     */
    public function simulateChildDone(
        string $childClass,
        array $result = [],
        ?string $finalState = null,
    ): self {
        $stateDefinition = $this->machine->state->currentStateDefinition;

        if (!$stateDefinition->hasMachineInvoke()) {
            throw new AssertionFailedError(
                "Cannot simulate child done: current state [{$stateDefinition->id}] does not have a machine invoke definition."
            );
        }

        $invokeDefinition = $stateDefinition->getMachineInvokeDefinition();

        if ($invokeDefinition->machineClass !== $childClass) {
            throw new AssertionFailedError(
                "Cannot simulate child done: current state delegates to [{$invokeDefinition->machineClass}], not [{$childClass}]."
            );
        }

        $this->machine->state->setInternalEventBehavior(
            type: InternalEvent::CHILD_MACHINE_DONE,
            placeholder: $childClass,
        );

        $doneEvent = ChildMachineDoneEvent::forChild([
            'result'        => $result,
            'output'        => $result,
            'machine_id'    => '',
            'machine_class' => $childClass,
            'final_state'   => $finalState,
        ]);

        $this->machine->definition->routeChildDoneEvent(
            $this->machine->state,
            $stateDefinition,
            $doneEvent,
        );

        if ($this->machine->definition->shouldPersist === false) {
            $this->trackStateEntry();
        }

        return $this;
    }

    /**
     * Simulate async child failure without real queues.
     */
    public function simulateChildFail(
        string $childClass,
        string $errorMessage = 'Simulated failure',
    ): self {
        $stateDefinition = $this->machine->state->currentStateDefinition;

        if (!$stateDefinition->hasMachineInvoke()) {
            throw new AssertionFailedError(
                "Cannot simulate child fail: current state [{$stateDefinition->id}] does not have a machine invoke definition."
            );
        }

        $this->machine->state->setInternalEventBehavior(
            type: InternalEvent::CHILD_MACHINE_FAIL,
            placeholder: $childClass,
        );

        $failEvent = ChildMachineFailEvent::forChild([
            'error_message' => $errorMessage,
            'machine_id'    => '',
            'machine_class' => $childClass,
            'output'        => [],
        ]);

        $this->machine->definition->routeChildFailEvent(
            $this->machine->state,
            $stateDefinition,
            $failEvent,
        );

        if ($this->machine->definition->shouldPersist === false) {
            $this->trackStateEntry();
        }

        return $this;
    }

    /**
     * Simulate async child timeout without real queues.
     */
    public function simulateChildTimeout(string $childClass): self
    {
        $stateDefinition = $this->machine->state->currentStateDefinition;

        if (!$stateDefinition->hasMachineInvoke()) {
            throw new AssertionFailedError(
                "Cannot simulate child timeout: current state [{$stateDefinition->id}] does not have a machine invoke definition."
            );
        }

        $timeoutEvent = EventDefinition::from([
            'type'    => 'CHILD_MACHINE_TIMEOUT',
            'payload' => ['machine_class' => $childClass],
            'version' => 1,
            'source'  => SourceType::INTERNAL,
        ]);

        $this->machine->definition->routeChildTimeoutEvent(
            $this->machine->state,
            $stateDefinition,
            $timeoutEvent,
        );

        if ($this->machine->definition->shouldPersist === false) {
            $this->trackStateEntry();
        }

        return $this;
    }

    // ═══════════════════════════════════════════
    //  Cross-Machine Communication (v2)
    // ═══════════════════════════════════════════

    /**
     * Enable communication recording for sendTo/raise assertions.
     */
    public function recordingCommunication(): self
    {
        CommunicationRecorder::startRecording();

        return $this;
    }

    /**
     * Assert sendTo() was called targeting the given machine class.
     */
    public function assertSentTo(string $machineClass, ?string $eventType = null): self
    {
        $records = CommunicationRecorder::getSendToRecords($machineClass);

        if ($records === []) {
            throw new AssertionFailedError(
                "Expected sendTo [{$machineClass}] to be called, but it was not."
            );
        }

        if ($eventType !== null) {
            $matched = false;

            foreach ($records as $record) {
                $type = is_array($record['event']) ? ($record['event']['type'] ?? null) : $record['event']->type;

                if ($type === $eventType) {
                    $matched = true;

                    break;
                }
            }

            if (!$matched) {
                throw new AssertionFailedError(
                    "Expected sendTo [{$machineClass}] with event type [{$eventType}], but no matching call found."
                );
            }
        }

        return $this;
    }

    /**
     * Assert sendTo() was NOT called targeting the given machine class.
     */
    public function assertNotSentTo(string $machineClass): self
    {
        $records = CommunicationRecorder::getSendToRecords($machineClass);

        if ($records !== []) {
            throw new AssertionFailedError(
                "Expected sendTo [{$machineClass}] not to be called, but it was called ".count($records).' time(s).'
            );
        }

        return $this;
    }

    /**
     * Assert dispatchTo() was called (wraps Queue::assertPushed for SendToMachineJob).
     */
    public function assertDispatchedTo(string $machineClass, ?string $eventType = null): self
    {
        Queue::assertPushed(
            SendToMachineJob::class,
            function (SendToMachineJob $job) use ($machineClass, $eventType): bool {
                if ($job->machineClass !== $machineClass) {
                    return false;
                }

                if ($eventType !== null && ($job->event['type'] ?? null) !== $eventType) {
                    return false;
                }

                return true;
            }
        );

        return $this;
    }

    /**
     * Assert a raised event was processed (appears in history).
     *
     * This checks that the event was raised AND processed — a stronger assertion
     * than checking the raise call alone. To test raise behavior when the event
     * may be guarded, test the action in isolation with State::forTesting().
     */
    public function assertRaisedEvent(string $eventType): self
    {
        $found = $this->machine->state->history
            ->pluck('type')
            ->contains($eventType);

        if (!$found) {
            throw new AssertionFailedError(
                "Expected event [{$eventType}] to have been raised and processed, but it was not found in history."
            );
        }

        return $this;
    }

    // ═══════════════════════════════════════════
    //  Forward Endpoint Helpers (v2)
    // ═══════════════════════════════════════════

    /**
     * Set up a running child for forward endpoint tests.
     *
     * Creates MachineChild DB record + persisted child machine instance.
     * Child starts in its initial state. Requires persistence enabled.
     */
    public function withRunningChild(string $childClass): self
    {
        if (!$this->machine->definition->shouldPersist) {
            throw new \RuntimeException(
                'withRunningChild() requires persistence. Remove withoutPersistence() to use.'
            );
        }

        $this->machine->persist();

        $parentRootEventId = $this->machine->state->history->first()?->root_event_id;
        $stateDefinition   = $this->machine->state->currentStateDefinition;

        if ($parentRootEventId === null) {
            throw new \RuntimeException('Cannot set up running child: no root_event_id.');
        }

        /** @var Machine $childMachine */
        $childMachine = $childClass::create();
        $childMachine->persist();

        $childRootEventId = $childMachine->state->history->first()->root_event_id;

        MachineChild::create([
            'parent_root_event_id' => $parentRootEventId,
            'parent_state_id'      => $stateDefinition->id,
            'parent_machine_class' => $this->machine->definition->machineClass ?? $childClass,
            'child_machine_class'  => $childClass,
            'child_root_event_id'  => $childRootEventId,
            'status'               => MachineChild::STATUS_RUNNING,
            'created_at'           => now(),
        ]);

        $this->machine->state->addActiveChild($childRootEventId);

        return $this;
    }

    // ═══════════════════════════════════════════
    //  Cleanup
    // ═══════════════════════════════════════════

    /**
     * Reset all fakes registered during this TestMachine's lifecycle.
     *
     * Clears: class-based behavior fakes, inline behavior fakes,
     * child machine fakes, and communication recordings.
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

        foreach ($this->fakedChildMachines as $childClass) {
            Machine::resetMachineFake($childClass);
        }
        $this->fakedChildMachines = [];

        // Also clear any Machine::fake() calls made outside fakingChild()
        Machine::resetMachineFakes();

        CommunicationRecorder::reset();

        return $this;
    }
}
