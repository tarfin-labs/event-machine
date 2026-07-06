# Testing DX Improvements

Improve the testing developer experience of EventMachine based on an audit of its largest real-world consumer (tarfin-core backend: ~494 machine test files across 13 domains). The audit found that the most widespread test boilerplate stems from missing package APIs, and that macro-level (machine-driving) tests drift into brittle legacy patterns where the package offers no first-class alternative.

## 1. Motivation (context)

Two audits of `core/backend/tests/Machines/` ground this spec:

**Micro-level findings (isolated behavior tests):**

- 67 files define a private context-builder helper under 6 different names (`makeContext` ×26, `buildContext` ×25, `buildState` ×10, `context` ×6, `createContext`, `makeState`).
- 110 files use `State::forTesting`; 17 files manually call `setMachineIdentity()` with inconsistent ids because `runWithState` provides no machine identity.
- `new Optional()` (74 occurrences / 34 files) and `Optional::create()` (51 / 16 files) coexist as two spellings of "absent value".
- The `retailer: new Optional(), retailerUser: new Optional()` prefix is copy-pasted into essentially every typed context construction.

**Macro-level findings (machine-driving tests):**

- Legacy tests rebuild machine sub-regions inline via `TestMachine::define(config: [...])` — hand-mirrors of production config that rot silently (CarSalesMachineTest, CarSalesMobileFlowTest ×7 helpers).
- Old assertion style `state()->matches('full.dotted.path')` + `addToAssertionCount(1)` instead of `assertState()`.
- The best file in the suite (FindeksMachineReportRetrievalTest) hand-writes a state×event→target transition table in a docblock and covers one edge per test — the package has no table-driven helper for this.
- Zero application-level adoption of `TracksPathCoverage` / `assertAllPathsCovered` despite the package shipping them.
- Per-test spy registration is verbose: 72 `::spy()` calls across 7 files, each test opening with 2-5 identical spy lines plus the same `->withoutPersistence()->fakingAllActions()` chain.

## 2. Scope

Package-only (event-machine repo): new testing APIs, tests, documentation, and agent skill updates. Migration of tarfin-core backend tests is out of scope (separate consumer-side effort); this spec only ships a migration guide section in the docs (see §9, `docs/best-practices/testing-strategy.md` row).

A docs-only prerequisite exists in the working tree (currently uncommitted): the "Base TestCase for Rich Typed Contexts" recipe in `docs/testing/recipes.md`, a tip in `docs/testing/isolated-testing.md`, and a note in `skills/event-machine/SKILL.md` §5. Committing that prerequisite is step 0 of the implementation order (§14); this spec builds on it and must not revert it.

## 3. `ContextManager::forTesting()`

A static factory on `ContextManager` that builds a test-ready context instance without hand-writing `Optional` boilerplate or machine identity.

### 3.1 Signature

```php
/**
 * @param  array<string, mixed>  $overrides
 */
public static function forTesting(array $overrides = [], ?string $machineId = 'test-machine-id'): static
```

### 3.2 Requirements

1. **Path detection**: the typed construction path applies when the resolved constructor of the called class is declared by any class other than `ContextManager` itself (i.e. `(new \ReflectionMethod(static::class, '__construct'))->getDeclaringClass()->getName() !== ContextManager::class`). This includes grandchildren that inherit a typed parent's constructor. Calling `forTesting()` on the base `ContextManager` uses the base path (requirement 3). A subclass whose resolved constructor IS the base `ContextManager` constructor (a subclass that never declares a typed constructor anywhere in its hierarchy) throws `InvalidArgumentException` telling the developer to declare a typed constructor — such subclasses are semi-unusable through `get()`/`set()` anyway (they dispatch via `ReflectionProperty` for all subclasses), so silently mis-constructing them is not acceptable.
2. **Typed path**: reflect over the resolved constructor's parameters and build arguments as follows, in precedence order: (a) a key present in `$overrides` is passed through as-is (models, scalars, `Optional` instances, `null` — always wins); (b) a parameter with a native default value is omitted so its default applies; (c) a parameter without a default whose declared type is `Spatie\LaravelData\Optional` itself or a union containing it is filled with `Optional::create()`; (d) any remaining parameter (required, not Optional-typed) is omitted and the underlying construction fails with its normal PHP/Data error (no swallowing). `mixed` and untyped parameters are NOT auto-filled (they fall under (b) or (d)). An override key that matches no constructor parameter throws `InvalidArgumentException` naming the unknown key and listing the valid parameter names.
3. **Base path** (base `ContextManager` only): `ContextManager::forTesting(['count' => 1])` is equivalent to `new ContextManager(['count' => 1])` plus machine identity.
4. **Machine identity**: after construction, call `setMachineIdentity(machineId: $machineId)` unless `$machineId` is `null` (explicit opt-out). The default id is the literal string `test-machine-id`.
5. **Canonical Optional convention**: the implementation and all documentation use `Optional::create()` — never `new Optional()`.
6. `forTesting()` constructs directly — bypassing `from()`/`validateAndCreate()` and Spatie Data's magic-creation pipeline. The implementation may use any direct construction mechanism (e.g. `ReflectionClass::newInstanceArgs()` with named arguments) — it is not required to use a literal `new static(...)` expression (which the PHPStan quality gate rejects for non-consistent constructors).

### 3.3 Example

```php
// Before (repeated in 67 files with 6 different helper names):
$context = new OrderContext(
    customer: new Optional(),
    retailer: new Optional(),
    order:    $order,
);
$context->setMachineIdentity(machineId: 'test-root-event-id');

// After:
$context = OrderContext::forTesting(['order' => $order]);
```

## 4. `runWithState()` accepts raw context

`InvokableBehavior::runWithState()` currently requires a pre-built `State`. Widen it so unit tests can skip the `State::forTesting` wrap.

### 4.1 Requirements

1. Change the first parameter type from `State` to `State|ContextManager|array`. The parameter keeps its current name `$state` — consumers call it with named arguments (`runWithState(state: $state, ...)`), so renaming would break them.
2. When a `ContextManager` or `array` is passed, wrap it via `State::forTesting($context)` internally; behavior with a `State` argument is unchanged (backward-compatible widening).
3. When an `array` is passed, the wrapped context is a base `ContextManager` (same semantics as `State::forTesting` today).
4. `State::forTesting()` itself is unchanged (no auto machine identity there — identity comes from `ContextManager::forTesting()`).

### 4.2 Example

```php
// Before:
$state = State::forTesting(OrderContext::forTesting(['order' => $order]));
ApproveAction::runWithState($state, eventBehavior: $event);

// After:
ApproveAction::runWithState(OrderContext::forTesting(['order' => $order]), eventBehavior: $event);
```

## 5. Table-driven transition assertions: `assertTransitions()`

A static entry point on `Machine` (via the same mechanism as `test()` / `startingAt()`) that verifies a state×event→target transition table, formalizing the hand-written pattern in the gold-standard consumer tests.

### 5.1 Signature

```php
/**
 * @param  array<int, array{from: string, event: string|array<string, mixed>|EventBehavior, to: string|null, context?: array<string, mixed>, guarded?: bool}>  $table
 * @param  array<string, mixed>  $context   Shared default context for all rows
 * @param  array<int, class-string>  $faking Behaviors to fake for all rows
 */
public static function assertTransitions(array $table, array $context = [], array $faking = []): void
```

Context is arrays only (both shared and row-level) — `startingAt()` accepts only arrays, and array context is trivially isolated per row. Tests needing a rich `ContextManager` instance should use individual `startingAt()` tests.

### 5.2 Requirements

1. For each row, boot a fresh machine at `from` via `static::startingAt($from, context: ..., faking: $faking)` (fresh machine per row; rows never share mutated context or state — persistence is already disabled by `startingAt()`, no extra call needed), send `event`, then assert the machine is in `to` via `assertState()`. `to` accepts the same forms `assertState()` accepts today (leaf state name or full dotted id). Rows run in order; the first failing row fails the test immediately (standard assertion semantics, no aggregation).
2. Row-level `context` is `array_replace`'d over the shared `$context` (top-level keys; row keys win). Keys absent from the row context are inherited from the shared context.
3. **Unhandled events**: sending an event with no transition defined from `from` throws `NoTransitionDefinitionFoundException` in the engine. `assertTransitions()` catches it and fails the row with a distinct "event not handled" message. This applies to guarded AND non-guarded rows — it protects against event-name typos.
4. **Non-guarded rows** must observe a real transition: the send must complete without `NoTransitionDefinitionFoundException` AND without a `TRANSITION_FAIL` internal event recorded for that send. A guard-blocked send on a non-guarded row fails with a distinct "transition blocked by guard" message — even when `to` equals `from`, so guard-blocked self-transition rows fail rather than pass vacuously.
5. **Guarded rows** (`guarded: true`) assert the transition was blocked instead of asserting `to`: either a `TRANSITION_FAIL` internal event was recorded (regular `GuardBehavior` block) or the send threw `MachineValidationException` (`ValidationGuardBehavior` rejection — caught and treated as blocked). In both cases the machine must remain in `from`. A guarded row whose transition actually happens fails with a message containing the row index, `from`, event type, "expected transition to be blocked", and the actual resulting state.
6. **Row-shape validation** happens before any row runs, each error identifying the row index via `InvalidArgumentException`: an empty `$table`; a row missing the `from`, `event`, or `to` key (or with wrong-typed values); `guarded: true` combined with non-null `to`; `to: null` without `guarded: true`; entries in `$faking` that are not `InvokableBehavior` subclass FQCNs (same validation contract as `spying()`, §6 — the implementation may share a helper).
7. Failure messages for target mismatches identify the failing row: index, `from`, event type, expected `to`, actual state.
8. Path coverage: rows are tracked by `PathCoverageTracker` exactly as individual `startingAt` + `send` tests are today (state entries recorded per row; guarded/blocked rows record only the `from` state entry, so coverage assertions stay deterministic).

### 5.3 Example

```php
FindeksMachine::assertTransitions([
    ['from' => 'findeks.report_retrieval.syncing_phones',  'event' => 'PHONES_SYNCED',   'to' => 'findeks.report_retrieval.checking_consent'],
    ['from' => 'findeks.report_retrieval.checking_consent', 'event' => 'CONSENT_MISSING', 'to' => 'findeks.awaiting_consent'],
    ['from' => 'findeks.awaiting_consent',                  'event' => 'RETRY_REQUESTED', 'to' => null, 'guarded' => true],
], context: ['tckn' => '12345678901'], faking: [StorePhonesAction::class]);
```

## 6. Batch spying and isolated preset

### 6.1 Requirements

1. **`TestMachine::spying(array $behaviorClasses): self`** — fluent instance method; calls `::spy()` on each class and returns `$this`. Accepts only FQCNs of `InvokableBehavior` subclasses (every `InvokableBehavior` carries the `Fakeable` trait, so no separate trait check is needed); any other entry (including inline-behavior string keys) throws `InvalidArgumentException` naming the entry, with a hint to use `InlineBehaviorFake::spy($key)` for inline behaviors. An empty array throws `InvalidArgumentException` (consistent with requirement 4). Note `spy()` is idempotent — spying a class already spied (e.g. by `fakingAllActions()`) is a harmless no-op.
2. **Timing**: `spying()` applies after machine initialization, so it does NOT observe initial-state entry actions or `@always` chains fired during boot. Tests that must observe boot-time behaviors keep using the pre-init `faking:` parameter of `test()`/`startingAt()`. This difference must be documented in `docs/testing/test-machine.md` and pinned by a test (§11.4).
3. **`Machine::testIsolated(array $context = [], array $faking = [])`** — static preset exactly equivalent to `static::test($context, faking: $faking)->fakingAllActions()` (persistence is already disabled by `test()`; no `withoutPersistence()` call is involved). Returns `TestMachine`. After `testIsolated()`, calling `fakingAllActions()` again with a non-empty `except:` list throws `LogicException` directing to the long form `test()->fakingAllActions(except:)` — already-applied spies cannot be selectively undone, and a silent no-op would produce silently wrong tests. `TestMachine` tracks whether all-actions faking has been applied to enforce this.
4. **`assertBehaviorRan()` accepts `string|array`** — array elements accept the same union the scalar form accepts today (behavior FQCN or inline-behavior string key, freely mixed). When given an array, asserts each entry ran; the failure message names the specific entries that did not run. An empty array throws `InvalidArgumentException`.

### 6.2 Example

```php
// Before (opens every test in 7 files):
BroadcastStateAction::spy();
StoreTcknAction::spy();
StoreCustomerPhoneAction::spy();
$machine = CarSalesMachine::test()->send([...]);

// After — batch spying on a machine that is otherwise running real behaviors:
$machine = CarSalesMachine::test()
    ->spying([BroadcastStateAction::class, StoreTcknAction::class, StoreCustomerPhoneAction::class])
    ->send([...]);
$machine->assertBehaviorRan([StoreTcknAction::class, StoreCustomerPhoneAction::class]);

// Fully isolated preset (all actions faked):
$machine = CarSalesMachine::testIsolated()->send([...]);
```

## 7. Fluent raised-event payload assertions

`InvokableBehavior::assertRaised()` currently returns `void`; payload checks require manual digging through the event queue returned by `runWithState()` plus a manual `selfValidate()` call (a pattern born from real production bugs WB-2695/WB-2705 in the consumer suite).

### 7.1 Requirements

1. `assertRaised(string $eventTypeOrClass)` returns a `RaisedEventAssertion` object instead of `void` (BC-safe for callers: existing callers ignore the return value; the existence assertion still executes inside `assertRaised()` itself).
2. `RaisedEventAssertion` exposes three fluent methods, each returning `$this`. All payload keys are interpreted as dot-notation paths (`Arr::get`/`Arr::has` semantics) — payload keys containing a literal dot cannot be asserted on (documented limitation): `withPayload(array $subset)` asserts each key exists in the raised event's payload with a strictly equal (`===`) value — array values compare the full array strictly; use dot-notation keys to assert nested keys individually; an empty `$subset` throws `InvalidArgumentException`; a missing key fails with a message naming the missing dot-path. `withoutPayloadKey(string $key)` asserts the payload does NOT contain the key (same dot-notation semantics, asserting absence). `validated()` calls `selfValidate()` on the matched raised event and fails the test with the validation error message if it throws.
3. Raised events may be `EventBehavior` instances or plain arrays (`raise()` accepts both). Payload is read from `$event->payload` for instances and `$event['payload'] ?? []` for arrays. An instance payload that is `null` or an `Optional` is treated as an empty array (so `withPayload` fails per-key with the standard missing-key message and `withoutPayloadKey` passes). `validated()` on an array-raised event fails the test with an explicit message ("raised event is a plain array; validated() requires an EventBehavior instance").
4. When multiple events of the matching type were raised, the fluent assertions apply to the first match; total-count mismatches remain the job of `assertRaisedCount()` (which counts ALL raised events, not per-type — unchanged).
5. `assertRaised()`'s existing signature/behavior for type-string and FQCN matching is unchanged.

### 7.2 Example

```php
// Before:
$queue = CheckProtocolAction::runWithState($state);
CheckProtocolAction::assertRaised(ProtocolRejectedEvent::class);
$event = $queue->first(fn ($e) => $e instanceof ProtocolRejectedEvent);
$this->assertSame(ApplicationDecisionType::PROTOCOL_REJECTED->value, $event->payload['applicationDecision'] ?? null);
$this->assertArrayNotHasKey('application_decision', $event->payload);
$event->selfValidate();

// After:
CheckProtocolAction::runWithState($state);
CheckProtocolAction::assertRaised(ProtocolRejectedEvent::class)
    ->withPayload(['applicationDecision' => ApplicationDecisionType::PROTOCOL_REJECTED->value])
    ->withoutPayloadKey('application_decision')
    ->validated();
```

## 8. `simulateChildDone()` finalState validation

`simulateChildDone()` already validates `childClass` against the current state's delegate and accepts typed `MachineOutput`. It does NOT validate `finalState` — a typo or renamed child final state passes silently and `@done.{state}` routing tests keep passing against a state that no longer exists.

### 8.1 Requirements

1. When `finalState` is non-null AND `childClass` is a `Machine` subclass: resolve the child's `MachineDefinition` and assert `finalState` matches a FINAL state in the child definition. On mismatch, throw `AssertionFailedError` listing the child's actual final state names.
2. **Leaf normalization**: the real completion pipeline always carries the LEAF state key (`@done.{leaf}` routing does an exact leaf-key lookup; there is no dotted-id normalization in the engine). `simulateChildDone()` therefore accepts either the leaf name or a full dotted id for validation convenience, but ALWAYS normalizes to the leaf key before building the `ChildMachineDoneEvent` — a dotted id validates against the specific final state whose full id matches, then its leaf segment is used for routing. A bare leaf name matches ANY final state with that leaf (when a child has multiple final states sharing a leaf name, any of them satisfies the leaf form — use the dotted id to pin a specific one; routing behavior is identical either way since routing only sees the leaf).
3. When `childClass` is a job class (job actor), `finalState` validation is skipped (jobs have no state tree). Classes that are neither `Machine` subclasses nor job classes are already rejected by the existing childClass-vs-delegate validation before this check runs — no new handling required.
4. `simulateChildFail()` is unchanged.
5. Documentation for `simulateChildDone` must show the typed `MachineOutput` form as the preferred `output:` argument (it is already supported but undocumented in the consumer-facing recipe).

## 9. Documentation updates

All new APIs must be documented in the same release:

| Doc file | Update |
|----------|--------|
| `docs/testing/isolated-testing.md` | `ContextManager::forTesting()` section (path detection, auto-Optional, machine identity, override precedence, unknown-key error); `runWithState` raw-context forms; fluent raised-event payload assertions (`RaisedEventAssertion`, incl. the literal-dot-key limitation) in the existing raised-events section |
| `docs/testing/test-machine.md` | `spying()` (incl. post-init timing vs pre-init `faking:`), `testIsolated()` (incl. the `except:` `LogicException`), `assertBehaviorRan(array)`, `assertTransitions()` reference, `simulateChildDone` finalState validation + leaf normalization + typed `MachineOutput` output |
| `docs/testing/transitions-and-paths.md` | Table-driven transition testing section featuring `assertTransitions()` — must cross-link `assertPath()` and state when to use each (assertPath: one sequential journey; assertTransitions: independent edges). Path Coverage section gains an application adoption guide: wiring `TracksPathCoverage` in a Laravel app (not just the package), `machine:coverage` in consumer CI |
| `docs/testing/recipes.md` | New recipe "Transition-Table Coverage for a Machine" (gold-standard pattern: one edge per row, `startingAt`-based); update the "Base TestCase for Rich Typed Contexts" recipe to build on `YourContext::forTesting()` instead of the hand-rolled `??` factory |
| `docs/best-practices/testing-strategy.md` | New section "Migrating Legacy Machine Tests" containing the full legacy→new mapping table of requirement 2 below, plus two anti-pattern subsections: "Anti-pattern: region-mirror `TestMachine::define`" (use `startingAt`) and "Anti-pattern: asserting dotted paths via `state()->matches()`" (wrong example shown next to the `assertState()` replacement) |

Numbered requirements:

1. Every new code block passes DocTest (use `ignore`/`no_run` attributes where app-level classes are referenced).
2. The "Migrating Legacy Machine Tests" section explicitly maps each legacy pattern to its replacement: manual context builders → `ContextManager::forTesting()`, region-mirror `TestMachine::define` → `startingAt()`, `state()->matches` asserts → `assertState()`, N× `::spy()` blocks → `spying()`, manual raised-payload digging → `assertRaised(...)->withPayload()`.
3. No new doc file is created; the SKILL.md §8 documentation-navigation table is unchanged.

## 10. Agent skill updates

1. `skills/event-machine/SKILL.md` §5 (Testing API Cheat-Sheet): add entries for `ContextManager::forTesting()` (on your context subclass: `YourContext::forTesting()`), `runWithState()` raw-context forms, `testIsolated()`, `spying()`, `assertBehaviorRan(array)`, `assertTransitions()`, and fluent `assertRaised(...)->withPayload()`.
2. SKILL.md anti-patterns/gotchas: add "never rebuild machine regions with `TestMachine::define` to start deep — use `startingAt`", "never assert full dotted state paths via `state()->matches` — use `assertState`", and "`simulateChildDone` now validates `finalState` against the child definition — a renamed child final state fails the parent's test (intended)".
3. Update the §5 note added by the step-0 prerequisite (see §2 and §14): point at `YourContext::forTesting()` as the primary construction tool, with the base-TestCase recipe as the layering pattern above it.

## 11. Package tests

1. Unit tests for `ContextManager::forTesting()`: typed subclass auto-Optional fill (union AND bare `Optional` types); override precedence over both defaults and auto-fill; native-default-wins over Optional auto-fill (parameter that is Optional-typed AND defaulted); base `ContextManager` passthrough; grandchild inheriting a typed parent's constructor uses the typed path; subclass with no typed constructor anywhere throws `InvalidArgumentException`; unknown override key throws `InvalidArgumentException` listing valid names; machine identity default and `null` opt-out; required-param failure passthrough.
2. Unit tests for widened `runWithState()`: array, `ContextManager`, and `State` inputs produce identical injection behavior; first parameter name stays `$state` (named-argument call compiles).
3. Feature tests for `assertTransitions()`: passing table; failing row message content (target mismatch); guarded rows (both `GuardBehavior`/TRANSITION_FAIL and `ValidationGuardBehavior`/exception paths); guarded row whose transition succeeds (message content); unhandled-event failure for guarded AND non-guarded rows; guard-blocked non-guarded row incl. the `to == from` vacuous-pass protection ("transition blocked by guard" message); row-level context merge over shared context; row-shape validation errors (empty table, missing keys, `guarded`+`to`, `to: null` without `guarded`, invalid `faking` entry); per-row isolation; path-coverage tracking integration (guarded rows record only `from`).
4. Tests for `spying()` (valid classes, inline-key rejection with hint, non-`InvokableBehavior` rejection, empty-array rejection); the §6.1.2 timing contract (a boot-time entry action is NOT recorded via `spying()` but IS via the pre-init `faking:` parameter); `testIsolated()` equivalence with the long form; `fakingAllActions(except:)` after `testIsolated()` throws `LogicException`; `assertBehaviorRan(array)` (mixed FQCN + inline keys, failure naming, empty-array rejection).
5. Tests for `RaisedEventAssertion`: `withPayload` subset/dot-notation/strict array comparison/empty-subset rejection/missing-key message; `withoutPayloadKey` (incl. dot-notation); `validated()` failure surfacing; null/Optional payload treated as empty array; array-raised events (payload access + `validated()` explicit failure); BC of bare `assertRaised()`.
6. Tests for `simulateChildDone` finalState validation: valid leaf name; valid dotted id (and that the built done event carries the LEAF key — routing works); leaf name matching any of multiple same-leaf finals; invalid name error listing finals; job-actor skip.
7. `composer quality` passes (pint, rector, the repo's phpstan config as run by `composer test:phpstan`, 100% type coverage, unit tests).
8. DocTest passes with 0 failures.

## 12. Backward compatibility

1. All changes are additive, parameter-widening, or return-type additions, with one deliberate behavioral exception (item 5); no existing test may need modification to keep passing except tests hitting that exception.
2. `assertRaised()` return-type change (`void` → object) is release-noted; PHP callers are unaffected. Consumers who OVERRIDE `assertRaised()` declaring `: void` would hit a covariance fatal — release notes must tell consumers to check for overrides.
3. `runWithState()` first-parameter widening is release-noted; the parameter name `$state` is unchanged (named-argument BC). Consumers who OVERRIDE `runWithState()` still declaring `State $state` would hit a contravariance fatal — release notes must tell consumers to check for overrides.
4. New method names (`forTesting` on ContextManager, `testIsolated`/`assertTransitions` on Machine, `spying` on TestMachine) can collide with methods consumers defined on their own subclasses, producing PHP incompatible-declaration fatals on upgrade. The consumer audit found no collisions (context helpers live on TestCases under other names), but the release notes must call out the four names so consumers can check before upgrading.
5. `simulateChildDone()` finalState validation is intentionally stricter: existing tests passing a stale or typo'd `finalState` will start failing. Such tests were asserting against nonexistent child states (bug-revealing). Release-note this as a potentially test-breaking strictness change.
6. New APIs follow existing naming conventions (`camelCase` fluent methods, `forTesting` factory naming parity with `EventBehavior::forTesting`).

## 13. Out of scope

- Migration of tarfin-core backend tests (consumer-side effort; enabled by the docs migration guide).
- A generatable fluent `EventBuilder` from event payload schemas (future work; `EventBuilder` already exists).
- A `ContextBuilder` fluent class layered above `forTesting()` (the base-TestCase recipe covers domain-level layering).
- Changes to scenario tooling (`MachineScenario`).
- Renaming or splitting misnamed consumer test files.
- Widening `startingAt()` / `test()` context parameters to accept `ContextManager` instances.
- A per-type raised-event count assertion (e.g. `RaisedEventAssertion::times()`) — `assertRaisedCount()` remains total-count only.

## 14. Implementation order

1. **Step 0**: commit the docs prerequisite currently in the working tree (recipes.md recipe + isolated-testing.md tip + SKILL.md note) — §9 row 4 and §10.3 edit that content.
2. §3 (`ContextManager::forTesting()`) first — §4's examples and docs build on it.
3. §4, §5, §6, §7, §8 are otherwise parallelizable; §5 and §6 may share the small faking/spying-entry validation helper (§5.2.6, §6.1.1) — implement the helper with whichever lands first.
4. §9 (docs), §10 (skill), §11 (tests accompany each feature as it lands; the quality gate and DocTest run last).
5. Per repo workflow rules, docs and skill updates ship in the same tag as the code — nothing here may be released partially.
