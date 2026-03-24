# State Machine Implementations: Test Suite Research

> Research date: 2026-03-25
> Purpose: Identify test patterns and coverage strategies from notable open-source state machine implementations to inform EventMachine testing hardening.

---

## Table of Contents

1. [W3C SCXML Conformance Test Suite (The Standard)](#1-w3c-scxml-conformance-test-suite)
2. [XState (TypeScript)](#2-xstate-typescript)
3. [Python Implementations](#3-python-implementations)
4. [Java/Kotlin Implementations](#4-javakotlin-implementations)
5. [C#/.NET Implementations](#5-cnet-implementations)
6. [C/C++ Implementations](#6-cc-implementations)
7. [Rust Implementations](#7-rust-implementations)
8. [Ruby Implementations](#8-ruby-implementations)
9. [Go Implementations](#9-go-implementations)
10. [Erlang/Elixir Implementations](#10-erlangelixir-implementations)
11. [Summary & Recommendations](#11-summary--recommendations)

---

## 1. W3C SCXML Conformance Test Suite

**The gold standard for statechart conformance testing.**

- **URL**: https://www.w3.org/Voice/2013/scxml-irp/
- **Mirror/Tutorial**: https://github.com/alexzhornyak/SCXML-tutorial (85 stars)
- **Extracted Suite**: https://github.com/statechart/scxml-test-suite

### Test Structure

- **159 mandatory tests** + **33 optional tests** = 192 total
- Tests derived from normative assertions in the SCXML 1.0 specification
- Each assertion maps to one or more `.txml` test files (template XML that can target different datamodels)
- The `manifest.xml` file maps assertions to tests

### Categories Covered

The IRP tests cover every normative assertion in the spec, organized by SCXML element/feature:

| Category | What It Tests |
|---|---|
| **`<scxml>`** | Initial state, datamodel binding (early/late) |
| **`<state>`** | Compound states, atomic states |
| **`<parallel>`** | Orthogonal regions, concurrent execution, done detection |
| **`<transition>`** | Event matching, condition evaluation, target resolution, type (internal/external), document order priority |
| **`<initial>`** | Default initial states, initial transitions |
| **`<final>`** | Final state entry, donedata, done events |
| **`<history>`** | Shallow history, deep history, default history content |
| **`<onentry>` / `<onexit>`** | Entry/exit action execution order |
| **`<raise>`** | Internal event queuing |
| **`<send>`** | Delayed events, event I/O processors, target expressions |
| **`<cancel>`** | Canceling delayed sends |
| **`<invoke>`** | Child session invocation, autoforward, finalize, parameter passing |
| **`<if>` / `<elseif>` / `<else>`** | Conditional execution |
| **`<foreach>`** | Iteration |
| **`<assign>`** | Datamodel mutation |
| **`<script>`** | Executable content |
| **`<datamodel>` / `<data>`** | Context initialization, src attribute |
| **Event processing** | Eventless transitions, macrostep semantics, event buffering |

### Why This Matters for EventMachine

The W3C suite is the **single most important reference** for our testing. It defines correct behavior for:
- Parallel region done detection (maps to our `@done` on parallel states)
- History state restoration (shallow/deep)
- Transition priority resolution in nested states
- Entry/exit action ordering across hierarchy levels
- Internal vs external transition semantics
- Delayed event (timer) send/cancel
- Child machine invocation and communication (maps to our machine delegation)

### Implementations That Run W3C Tests

| Implementation | Mandatory Pass Rate | Notes |
|---|---|---|
| USCXML (C++) | ~155/159 | Most complete |
| SCION (JavaScript) | 159/159 mandatory, 33/33 optional | Fully conformant |
| Qt SCXML (C++) | ~155/159 | Qt framework integration |
| Apache Commons SCXML (Java) | Partial | Working toward 2.0 compliance |
| python-statemachine (Python) | Includes W3C `.scxml` test files | 3.0+ |

---

## 2. XState (TypeScript)

- **GitHub**: https://github.com/statelyai/xstate
- **Stars**: 29,355
- **Language**: TypeScript
- **SCXML Compliance**: Partial (v5 closer to spec than v4)

### Feature Support

| Feature | Supported |
|---|---|
| Parallel/orthogonal states | Yes |
| Async operations (actors) | Yes (invoke, spawn) |
| Hierarchical states | Yes |
| Guards | Yes |
| Actions (entry/exit/transition) | Yes |
| History states | Yes (shallow + deep) |
| Delayed transitions/timers | Yes (`after`) |
| Invocation (child machines) | Yes |
| SCXML compliance | Partial (custom extensions) |

### Test Suite Structure

**Location**: `packages/core/test/`
**Framework**: Vitest
**Test files**: 56 `.test.ts` files + fixtures + examples

| Test File | Size (bytes) | What It Tests |
|---|---|---|
| `types.test.ts` | 104,705 | Type-level correctness (huge!) |
| `actions.test.ts` | 99,982 | Entry/exit/transition actions, assign, raise, sendTo |
| `invoke.test.ts` | 90,589 | Child machine/promise/callback/observable invocation |
| `setup.types.test.ts` | 72,236 | Type inference for machine setup |
| `interpreter.test.ts` | 47,093 | Actor lifecycle, start/stop, event processing |
| `actor.test.ts` | 44,804 | Actor system, communication patterns |
| `history.test.ts` | 34,595 | Shallow/deep history, history in parallel states |
| `guards.test.ts` | 33,639 | Guard conditions, `in` guards, custom guards |
| `parallel.test.ts` | 31,093 | Parallel regions, done detection, re-entry |
| `final.test.ts` | 30,900 | Final states, donedata, done events |
| `inspect.test.ts` | 26,068 | Inspection/observation API |
| `errors.test.ts` | 24,904 | Error handling, error events |
| `transition.test.ts` | 23,331 | Transition resolution, targets, types |
| `scxml.test.ts` | 18,784 | W3C SCXML conformance test runner |
| `transient.test.ts` | 16,954 | Eventless (always) transitions |
| `system.test.ts` | 14,461 | Actor system coordination |
| `predictableExec.test.ts` | 12,848 | Predictable execution order |
| `rehydration.test.ts` | 12,409 | State persistence and restoration |
| `stateIn.test.ts` | 11,313 | `in()` state guards |
| `eventDescriptors.test.ts` | 11,243 | Event matching patterns |
| `state.test.ts` | 11,859 | State value representation |
| `deep.test.ts` | 10,869 | Deeply nested state hierarchies |
| `meta.test.ts` | 10,285 | State metadata |
| `emit.test.ts` | 10,165 | Event emission |
| `assign.test.ts` | 9,798 | Context assignment |
| `route.test.ts` | 9,369 | Routing integration |
| `waitFor.test.ts` | 9,523 | waitFor utility |
| `internalTransitions.test.ts` | 8,778 | Internal vs external transitions |
| `after.test.ts` | 8,225 | Delayed transitions (timers) |
| `microstep.test.ts` | 7,869 | Microstep semantics |
| `input.test.ts` | 7,287 | Actor input |
| `deterministic.test.ts` | 7,130 | Deterministic behavior |
| `multiple.test.ts` | 6,011 | Multiple machines |
| `select.test.ts` | 7,306 | Selector functions |
| `json.test.ts` | 4,494 | JSON serialization |

### Notable Test Patterns

1. **Parallel state tests** (`parallel.test.ts`, ~31KB):
   - Transition actions called in document order across parallel regions
   - Same-level parallel region action ordering
   - Actions at different hierarchy levels within parallel regions
   - History states on parallel states directly
   - `done.state.*` events in parallel+history combos
   - Re-entering parallel regions targeting another region
   - Guard behavior within parallel configurations

2. **History state tests** (`history.test.ts`, ~35KB):
   - One of the largest test files, indicating history is notoriously tricky
   - Shallow vs deep history semantics
   - History in parallel states
   - Default history content
   - History with nested compound states

3. **Invocation tests** (`invoke.test.ts`, ~91KB):
   - The largest behavioral test file
   - Promise invocation, callback invocation, observable invocation
   - Machine invocation (child machines)
   - Error handling in invoked services
   - Auto-forwarding events to children
   - Multiple simultaneous invocations
   - Invocation lifecycle (start/stop/error)

4. **SCXML conformance** (`scxml.test.ts`):
   - Runs W3C SCXML test files from `fixtures/scxml/` directory
   - Validates XState behavior against the standard

5. **Type-level testing** (`types.test.ts`, `setup.types.test.ts`, ~177KB combined):
   - Massive investment in compile-time type safety
   - Ensures type inference works correctly for all machine configurations

### Key Takeaway for EventMachine

XState's test suite is the **most comprehensive behavioral test suite** of any state machine library. Their `invoke.test.ts` (child machines), `parallel.test.ts`, `history.test.ts`, and `actions.test.ts` files are must-study references. The sheer size of their type tests (177KB) shows how much effort goes into type safety.

---

## 3. Python Implementations

### 3a. pytransitions/transitions

- **GitHub**: https://github.com/pytransitions/transitions
- **Stars**: 6,466
- **Language**: Python

#### Feature Support

| Feature | Supported |
|---|---|
| Parallel/orthogonal states | Yes (via extensions) |
| Async operations | Yes (`AsyncMachine`) |
| Hierarchical states | Yes (`HierarchicalMachine`) |
| Guards (conditions) | Yes |
| Actions (callbacks) | Yes (before/after/prepare) |
| History states | No |
| Delayed transitions/timers | No (manual) |

#### Test Suite

**Location**: `tests/`
**Files**: 19 test files

| Test File | What It Tests |
|---|---|
| `test_core.py` | Core FSM: transitions, callbacks, states, conditions |
| `test_nesting.py` | Hierarchical (nested) state machines |
| `test_parallel.py` | Parallel state execution |
| `test_async.py` | Async machine operations |
| `test_threading.py` | Thread safety |
| `test_reuse.py` | Machine reuse patterns |
| `test_factory.py` | Machine factory patterns |
| `test_markup.py` | Machine serialization/deserialization |
| `test_enum.py` | Enum-based states |
| `test_states.py` | State objects and behavior |
| `test_add_remove.py` | Dynamic state/transition addition/removal |
| `test_experimental.py` | Experimental features |

#### Notable Patterns
- Separate test files for parallel, async, and threading -- good separation of concerns
- Explicit thread safety testing
- Dynamic machine modification tests (add/remove states at runtime)

---

### 3b. python-statemachine

- **GitHub**: https://github.com/fgmacedo/python-statemachine
- **Stars**: 1,215
- **Language**: Python
- **SCXML Compliance**: Yes (v3.0+, includes W3C test files)

#### Feature Support

| Feature | Supported |
|---|---|
| Parallel/orthogonal states | Yes (v3.0+) |
| Async operations | Yes |
| Hierarchical (compound) states | Yes |
| Guards (conditions) | Yes (with algebra) |
| Actions (callbacks) | Yes |
| History states | Yes |
| Delayed transitions | Yes |
| SCXML compliance | Yes (runs W3C tests) |

#### Test Suite

**Location**: `tests/`
**Files**: 53 test files -- very thorough

Key test files organized by feature:

| Test File | What It Tests |
|---|---|
| `test_statechart_parallel.py` | Parallel regions |
| `test_statechart_compound.py` | Compound (hierarchical) states |
| `test_statechart_history.py` | History pseudo-states |
| `test_statechart_delayed.py` | Delayed transitions |
| `test_statechart_eventless.py` | Eventless (automatic) transitions |
| `test_statechart_donedata.py` | Done data passing |
| `test_statechart_error.py` | Error handling |
| `test_statechart_in_condition.py` | In-state conditions |
| `test_scxml_units.py` | W3C SCXML unit tests |
| `test_async.py` | Async operations |
| `test_async_futures.py` | Future-based async |
| `test_threading.py` | Thread safety |
| `test_invoke.py` | Child process invocation |
| `test_conditions_algebra.py` | Complex guard expressions |
| `test_callbacks_isolation.py` | Callback isolation between instances |
| `test_mock_compatibility.py` | Mock/fake support for testing |
| `test_rtc.py` | Run-to-completion semantics |

#### Notable Patterns
- **W3C SCXML conformance tests** included directly
- Dedicated test files for each statechart feature (parallel, compound, history, delayed, eventless)
- `test_mock_compatibility.py` -- testing the testability of state machines (meta!)
- `test_rtc.py` -- explicit run-to-completion semantics testing
- `test_callbacks_isolation.py` -- ensures callbacks don't bleed between instances

### Key Takeaway for EventMachine
python-statemachine v3.0+ is the **closest Python equivalent** to EventMachine in feature set. Their test organization (one file per statechart feature) and inclusion of W3C SCXML tests is exemplary.

---

### 3c. Sismic

- **GitHub**: https://github.com/AlexandreDecan/sismic
- **Stars**: 159
- **Language**: Python
- **Academic paper**: Published in SoftwareX journal

#### Feature Support

Full UML 2 statechart support: compound, orthogonal, history (shallow+deep), guards, actions, eventless transitions, internal transitions, priorities.

#### Test Suite

**Location**: `tests/`
**Files**: 12 test files

| Test File | What It Tests |
|---|---|
| `test_interpreter.py` | Core interpreter semantics |
| `test_model.py` | Statechart model validation |
| `test_bdd.py` | Behavior-driven development integration |
| `test_contract.py` | Design-by-contract assertions |
| `test_property.py` | Property statecharts (runtime verification) |
| `test_io.py` | Import/export (YAML, dict) |
| `test_code.py` | Python code execution in statecharts |
| `test_runner.py` | Statechart runner/scheduler |
| `test_clock.py` | Time-based behavior |
| `test_examples.py` | Example statecharts |

#### Notable Patterns
- **Property statecharts** -- testing state machines WITH state machines (runtime verification)
- **Design-by-contract** -- preconditions/postconditions/invariants on states
- **BDD integration** -- Given/When/Then style statechart testing
- Academic rigor: formal semantics based on SCXML

---

## 4. Java/Kotlin Implementations

### 4a. Spring Statemachine

- **GitHub**: https://github.com/spring-projects/spring-statemachine
- **Stars**: 1,658
- **Language**: Java

#### Feature Support

| Feature | Supported |
|---|---|
| Parallel/orthogonal states | Yes (regions, fork/join) |
| Async operations | Yes (reactive) |
| Hierarchical states | Yes |
| Guards | Yes |
| Actions (entry/exit/transition) | Yes |
| History states | Yes |
| Delayed transitions/timers | Yes |
| Persistence | Yes (multiple backends) |

#### Test Suite

**Location**: Multiple modules:
- `spring-statemachine-core/src/test/` (core tests)
- `spring-statemachine-build-tests/src/test/` (integration tests)

Core test directory contains **14+ test files** plus subdirectories for:
- `action/` -- Action execution tests
- `guard/` -- Guard condition tests
- `listener/` -- Listener notification tests
- `persist/` -- State persistence tests
- `transition/` -- Transition logic tests
- `trigger/` -- Trigger mechanism tests
- `config/` -- Configuration tests
- `ensemble/` -- Distributed state machine tests
- `security/` -- Security integration tests
- `annotation/` -- Annotation-based config tests

Key test files:
- `RegionMachineTests.java` -- Parallel region behavior
- `SubStateMachineTests.java` -- Hierarchical states
- `EventDeferTests.java` -- Event deferral
- `ReactiveTests.java` -- Reactive/async operations
- `StateMachineResetTests.java` -- State restoration
- `ForkJoinEntryExitTests.java` -- Fork/join pseudo-states
- `TimerSmokeTests.java` -- Timer-based transitions
- `LinkedRegionsTests.java` -- Cross-region communication

Build tests include: `ChoiceExitTests`, `EndSmokeTests`, `LinkedPseudoStatesTests`

#### Notable Patterns
- **StateMachineTestPlanBuilder** -- dedicated test DSL for state machines
- Parallel send testing (events sent to multiple machines via parallel threads)
- Persistence tests across multiple backends (Redis, JPA, etc.)
- Security integration tests
- Reactive (non-blocking) state machine tests

---

### 4b. Squirrel Foundation

- **GitHub**: https://github.com/hekailiang/squirrel
- **Stars**: 2,250
- **Language**: Java

#### Feature Support

Hierarchical states, parallel states, guards, actions, history states. Type-safe implementation.

#### Test Suite

**Location**: `squirrel-foundation/src/test/java/org/squirrelframework/foundation/fsm/`
**Files**: 17 test files + 4 subdirectories

| Test File | What It Tests |
|---|---|
| `ParallelStateMachineTest.java` | Parallel state execution |
| `HierarchicalStateMachineTest.java` | Nested/hierarchical states |
| `DeclarativeStateMachineTest.java` | Annotation-based declaration |
| `ConventionalStateMachineTest.java` | Convention-based declaration |
| `LinkedStateMachineTest.java` | Linked/child state machines |
| `CollaboratingStateMachineTest.java` | Cross-machine communication |
| `StateMachineExceptionTest.java` | Error handling |
| `StateMachineExtensionTest.java` | Extension points |
| `StateMachineImporterTest.java` | SCXML import |
| `StateMachineVerifyTest.java` | Machine validation |
| `WeightedActionTest.java` | Action priority/ordering |
| `PerformanceTest.java` | Performance benchmarks |
| `AbstractStateMachineTest.java` | Base test infrastructure |

Subdirectories: `atm/`, `cssparser/`, `samples/`, `snake/` (example apps as tests)

#### Notable Patterns
- **SCXML import tests** -- can import SCXML definitions
- **Performance tests** included in the test suite
- **Machine verification/validation** tests
- Example-driven testing (ATM, Snake game as test fixtures)

---

### 4c. stateless4j

- **GitHub**: https://github.com/stateless4j/stateless4j
- **Stars**: 930
- **Language**: Java (port of .NET Stateless)

Simple FSM library. Hierarchical states, guards, entry/exit actions. No parallel states or history.

**Test location**: `src/test/java/` -- `StateMachineTests.java` (single file)

---

### 4d. Apache Commons SCXML

- **GitHub**: https://github.com/apache/commons-scxml
- **Stars**: 65
- **Language**: Java
- **SCXML Compliance**: Primary goal (targeting 2.0)

#### Test Suite

**Location**: `src/test/java/org/apache/commons/scxml2/`

Contains both custom tests and W3C IRP test runner:
- `SCXMLExecutorTest.java` -- Core executor tests
- `TieBreakerTest.java` -- Transition priority resolution (6 test XML files!)
- `w3c/` directory -- W3C conformance test runner
- `invoke/` -- Invocation tests
- `semantics/` -- SCXML semantics tests
- `issues/` -- Regression tests for reported issues
- `history-*.xml` -- History test fixtures (deep, shallow, parallel, default)
- `transitions-*.xml` -- Transition test fixtures (6+ files)

#### Notable Patterns
- **Tie-breaker tests** are fascinating -- 6 different XML files testing transition priority resolution
- **W3C test runner** that can download and execute IRP tests
- History test fixtures specifically for parallel+history combinations

---

## 5. C#/.NET Implementations

### 5a. Stateless

- **GitHub**: https://github.com/dotnet-state-machine/stateless
- **Stars**: 6,138
- **Language**: C#

#### Feature Support

| Feature | Supported |
|---|---|
| Parallel/orthogonal states | No |
| Async operations | Yes |
| Hierarchical states | Yes (substates) |
| Guards | Yes |
| Actions (entry/exit/transition) | Yes |
| History states | No |
| Delayed transitions/timers | No |

#### Test Suite

**Location**: `test/Stateless.Tests/`
**Files**: 32 test files

| Test File | What It Tests |
|---|---|
| `StateMachineFixture.cs` | Core state machine behavior |
| `AsyncActionsFixture.cs` | Async entry/exit/transition actions |
| `AsyncFiringModesFixture.cs` | Async firing modes |
| `AsyncTransitionTests.cs` | Async transitions |
| `FiringModesFixture.cs` | Immediate vs queued firing |
| `InitialTransitionFixture.cs` | Initial state transitions |
| `InternalTransitionFixture.cs` | Internal transitions (no exit/entry) |
| `InternalTransitionAsyncFixture.cs` | Async internal transitions |
| `ActiveStatesFixture.cs` | Active state queries |
| `IgnoredTriggerBehaviourFixture.cs` | Ignored triggers |
| `DynamicTriggerBehaviourFixture.cs` | Dynamic trigger parameters |
| `DynamicAsyncTriggerBehaviourFixture.cs` | Async dynamic triggers |
| `OnTransitionedEventTests.cs` | Transition event notifications |
| `ReflectionFixture.cs` | Runtime introspection |
| `DotGraphFixture.cs` | DOT graph export |
| `MermaidGraphFixture.cs` | Mermaid diagram export |
| `StateRepresentationFixture.cs` | State representation internals |
| `TriggerBehaviourFixture.cs` | Trigger behavior internals |
| `SynchronizationContextFixture.cs` | Thread synchronization |

#### Notable Patterns
- **Firing modes testing** (immediate vs queued) -- relevant to our run-to-completion semantics
- **Synchronization context tests** -- thread safety
- Separate async variants for each test category
- Dynamic trigger behavior with parameters

---

### 5b. MassTransit (Automatonymous, now integrated)

- **GitHub**: https://github.com/MassTransit/MassTransit
- **Stars**: 7,704
- **Language**: C#

Automatonymous (state machine library) was merged into MassTransit v8. The saga state machine tests are extensive.

#### Test Suite

**Location**: `tests/MassTransit.Tests/SagaStateMachineTests/`
**Files**: 42 test files

| Test File | What It Tests |
|---|---|
| `CompositeEvent_Specs.cs` | Composite events (multiple events -> one trigger) |
| `CompositeEventsInInitialState_Specs.cs` | Composite events at start |
| `Request_Specs.cs` | Request/response patterns |
| `Request2_Specs.cs`, `Request3_Specs.cs` | Multi-step request chains |
| `RequestRequest_Specs.cs` | Nested request patterns |
| `ScheduleTimeout_Specs.cs` | Timeout scheduling |
| `ScheduleCorrelation_Specs.cs` | Schedule with correlation |
| `Fault_Specs.cs` | Fault handling |
| `CatchFault_Specs.cs` | Catching faults |
| `CatchInitial_Specs.cs` | Catching errors in initial state |
| `FaultRescue_Specs.cs` | Fault recovery |
| `CorrelateFaultById_Specs.cs` | Fault correlation |
| `Finalize_Specs.cs` | State machine finalization |
| `Outbox_Specs.cs` | Outbox pattern |
| `OutboxFault_Specs.cs` | Outbox fault handling |
| `InMemoryDeadlock_Specs.cs` | Deadlock detection/prevention |
| `Partitioning_Specs.cs` | Partitioned state machines |
| `Ignore_Specs.cs` | Ignored events |
| `MissingInstance_Specs.cs` | Missing saga instance handling |
| `UncorrelatedMessage_Specs.cs` | Uncorrelated message handling |
| `Testing_Specs.cs` | Test harness for state machines |
| `DynamicEvent_Specs.cs` | Dynamic event handling |
| `Choir_Specs.cs` | Multi-machine choreography |

#### Notable Patterns
- **Composite events** -- combining multiple events into a single trigger (like our parallel region done detection)
- **Request/response chains** -- multi-step async patterns
- **Deadlock detection tests** -- critical for concurrent state machines
- **Outbox pattern tests** -- reliable messaging with state machines
- **Fault recovery** -- extensive fault handling test coverage
- **Correlation tests** -- matching events to state machine instances
- **Choreography tests** (Choir_Specs) -- multiple machines coordinating

### Key Takeaway for EventMachine
MassTransit's saga state machine tests are the **best reference for distributed/async state machine testing**. Their composite events, request/response patterns, deadlock detection, and fault recovery tests are directly relevant to our parallel regions and machine delegation.

---

### 5c. Appccelerate State Machine

- **GitHub**: https://github.com/appccelerate/statemachine
- **Stars**: 544
- **Language**: C#

Hierarchical state machine with async support and reporting. Good documentation.

---

## 6. C/C++ Implementations

### 6a. Boost.Statechart

- **GitHub**: https://github.com/boostorg/statechart (mirror)
- **Stars**: 33 (part of Boost, actual usage vastly higher)
- **Language**: C++

#### Feature Support

Full UML statechart support: orthogonal regions, history (shallow+deep), guards, actions, deferred events, compile-time validation.

#### Test Suite

**Location**: `test/`
**Files**: 32 C++ test files

| Test File | What It Tests |
|---|---|
| `TransitionTest.cpp` | Transition semantics |
| `HistoryTest.cpp` | History states |
| `DeferralTest.cpp` | Event deferral |
| `DeferralBug.cpp` | Deferral regression |
| `TerminationTest.cpp` | Machine termination |
| `FifoSchedulerTest.cpp` | FIFO event scheduling |
| `CustomReactionTest.cpp` | Custom reaction handlers |
| `InStateReactionTest.cpp` | In-state reactions |
| `StateCastTest.cpp` | State casting |
| `StateIterationTest.cpp` | Iterating over active states |
| `TriggeringEventTest.cpp` | Accessing triggering event |
| `TypeInfoTest.cpp` | Runtime type information |
| `InconsistentHistoryTest[1-8].cpp` | 8 tests for history edge cases! |
| `InvalidChartTest[1-3].cpp` | Invalid machine definition detection |
| `InvalidTransitionTest[1-2].cpp` | Invalid transition detection |
| `UnsuppDeepHistoryTest.cpp` | Unsupported deep history scenarios |

#### Notable Patterns
- **8 separate tests for inconsistent history** -- shows how tricky history states are
- **Compile-time validation tests** (InvalidChart, InvalidTransition) -- machines that should fail to compile
- **Event deferral** testing (DeferralTest + DeferralBug regression)
- **Triggering event access** -- accessing the event that caused a transition (we have `triggeringEvent`)

---

### 6b. USCXML

- **GitHub**: https://github.com/tklab-tud/uscxml
- **Stars**: 114
- **Language**: C/C++
- **SCXML Compliance**: Near-complete (~155/159 mandatory)

Full SCXML interpreter and compiler. Runs the W3C test suite. Can transpile to ANSI-C and VHDL.

**Test location**: `test/` with subdirectories: `w3c/` (W3C tests), `issues/`, `benchmarks/`, `src/`

---

### 6c. Quantum Leaps QP/C

- **GitHub**: https://github.com/QuantumLeaps/qpc
- **Stars**: 1,261
- **Language**: C

#### Feature Support

Hierarchical state machines (UML statecharts), active objects (actors), event-driven architecture. Designed for embedded systems.

#### Test Harness: QUTest

- **Trace-based testing** -- CUT produces a trace, expectations verified on host
- Claims **100% line coverage** and **100% MC/DC code coverage**
- Dual-targeting: tests run on both host and embedded target

#### Notable Patterns
- **MC/DC coverage** -- Modified Condition/Decision Coverage, the strictest practical coverage criterion
- **Trace-based testing** -- unique approach, records all state transitions and verifies the trace
- Embedded-focused: tests that run on actual hardware

---

## 7. Rust Implementations

### 7a. Statig

- **GitHub**: https://github.com/mdeloof/statig
- **Stars**: 765
- **Language**: Rust

#### Feature Support

Hierarchical state machines with compile-time verification via procedural macros.

#### Test Suite

**Location**: `statig/tests/`
**Files**: 15 test files

| Test File | What It Tests |
|---|---|
| `transition.rs` | State transitions |
| `transition_macro.rs` | Macro-based transitions |
| `async_transition.rs` | Async state transitions |
| `async_transition_macro.rs` | Async macro-based transitions |
| `hooks.rs` | Entry/exit hooks |
| `async_hooks.rs` | Async entry/exit hooks |
| `external_context.rs` | External context injection |
| `generics.rs` | Generic state machines |
| `state_local_storage.rs` | Per-state data storage |
| `state_local_storage_macro.rs` | Macro-based per-state data |
| `serde.rs` | Serialization/deserialization |
| `default.rs` | Default state behavior |
| `config_macro.rs` | Configuration macros |
| `ui_tests.rs` | Compile-time error tests |

#### Notable Patterns
- **Compile-time UI tests** -- tests that verify specific compiler errors are produced for invalid machines
- **Per-state local storage** -- unique feature, tests data tied to individual states
- Separate sync/async test variants for each feature

---

### 7b. Finny

- **GitHub**: https://github.com/hashmismatch/finny.rs
- **Stars**: 73
- **Language**: Rust

FSM library with procedural macro for code generation. Supports guards, actions, timers. No parallel states.

---

## 8. Ruby Implementations

### 8a. AASM

- **GitHub**: https://github.com/aasm/aasm
- **Stars**: 5,187
- **Language**: Ruby

#### Feature Support

| Feature | Supported |
|---|---|
| Parallel states | No |
| Async operations | No |
| Hierarchical states | No (flat FSM) |
| Guards | Yes |
| Actions (callbacks) | Yes (extensive) |
| History states | No |
| Delayed transitions | No |

#### Test Suite

**Location**: `spec/unit/`
**Files**: 46+ spec files

Notable test categories:
- **Guard tests**: `guard_spec.rb`, `guard_multiple_spec.rb`, `guard_with_params_spec.rb`, `guard_without_from_specified_spec.rb`, `guard_arguments_check_spec.rb`, `multiple_transitions_that_differ_only_by_guard_spec.rb`
- **Callback tests**: `callbacks_spec.rb`, `callback_multiple_spec.rb`
- **Event tests**: `event_spec.rb`, `event_multiple_spec.rb`, `event_naming_spec.rb`, `event_with_keyword_arguments_spec.rb`
- **Edge cases**: `edge_cases_spec.rb`, `memory_leak_spec.rb`, `reloading_spec.rb`
- **Persistence**: `persistence/` directory with multiple ORM adapters
- **Multiple machines**: `simple_multiple_example_spec.rb`, `complex_multiple_example_spec.rb`, `namespaced_multiple_example_spec.rb`

#### Notable Patterns
- **Memory leak tests** -- critical for long-running processes
- **Reloading tests** -- Rails class reloading compatibility
- **Multiple persistence adapters** tested (ActiveRecord, Mongoid, etc.)
- **RSpec matchers** -- custom test DSL (`rspec_matcher_spec.rb`)
- Very thorough guard testing (6 different guard test files!)

---

### 8b. Statesman

- **GitHub**: https://github.com/gocardless/statesman
- **Stars**: 1,882
- **Language**: Ruby

Audit-trail focused state machine. Used in production at GoCardless.

**Test location**: `spec/statesman/` -- 7 spec files covering machine, guard, callback, config, adapters, exceptions, utils.

---

## 9. Go Implementations

### 9a. looplab/fsm

- **GitHub**: https://github.com/looplab/fsm
- **Stars**: 3,348
- **Language**: Go

#### Feature Support

Simple FSM with callbacks. No hierarchical states, parallel states, or guards. Event-driven transitions.

#### Test Suite

**Location**: Root directory
**Files**: `fsm_test.go`, `mermaid_visualizer_test.go`

Single test file approach typical of Go libraries. Tests cover transitions, callbacks, error conditions, concurrent access.

---

## 10. Erlang/Elixir Implementations

### 10a. Erlang OTP gen_statem

- **GitHub**: https://github.com/erlang/otp (part of OTP)
- **Language**: Erlang
- **Status**: The reference implementation for production state machines

#### Test Suite

**Location**: `lib/stdlib/test/gen_statem_SUITE.erl` + `gen_statem_SUITE_data/`

OTP's gen_statem has a comprehensive Common Test suite. As part of OTP, it undergoes rigorous testing for:
- State function callback mode
- Handle event callback mode
- State enter calls
- Timeouts (event, state, generic)
- Postpone events
- Internal events
- Code change/hot upgrade
- Process monitoring
- Concurrent access

#### Notable Patterns
- **Hot code upgrade testing** -- changing state machine code while running
- **Event postponement** -- deferring events to be reprocessed later
- **Multiple timeout types** -- event timeout, state timeout, generic timeout
- Part of the most battle-tested runtime platform (OTP)

---

### 10b. Machinery (Elixir)

- **GitHub**: https://github.com/joaomdmoura/machinery
- **Stars**: 566
- **Language**: Elixir

Thin state machine layer for structs. Guards, callbacks. No parallel/hierarchical states.

---

### 10c. GenStateMachine (Elixir)

- **GitHub**: https://github.com/ericentin/gen_state_machine
- **Stars**: 313
- **Language**: Elixir

Idiomatic Elixir wrapper for gen_statem. Inherits all gen_statem capabilities.

---

## 11. Summary & Recommendations

### Tier 1: Must-Study Test Suites

These have the most comprehensive and relevant test coverage for EventMachine:

| Implementation | Why Study It | Key Test Areas |
|---|---|---|
| **W3C SCXML IRP Tests** | The standard. 159 mandatory + 33 optional tests covering every normative assertion. | Parallel semantics, history, transition priority, invoke, done detection, event processing order |
| **XState** (29K stars) | Largest, most complete statechart test suite. 56 test files, ~800KB of test code. | Parallel states (31KB), history (35KB), invocation (91KB), actions (100KB), SCXML conformance, type safety |
| **MassTransit** (7.7K stars) | Best distributed/async state machine tests. 42 saga test files. | Composite events, deadlock detection, fault recovery, request/response chains, outbox pattern, correlation |
| **python-statemachine** (1.2K stars) | Most similar architecture to EventMachine. 53 test files with W3C conformance. | Parallel, compound, history, delayed, eventless, mock compatibility, RTC semantics, SCXML units |

### Tier 2: Valuable for Specific Areas

| Implementation | Valuable For |
|---|---|
| **Spring Statemachine** | Persistence testing, security integration, region tests, reactive/async patterns |
| **Squirrel Foundation** | Parallel state tests, machine verification, SCXML import, performance tests |
| **Boost.Statechart** | History edge cases (8 tests!), compile-time validation, event deferral, triggering event access |
| **AASM** | Guard testing depth (6 files!), memory leaks, persistence adapters, class reloading |
| **Sismic** | Property-based verification, design-by-contract, BDD integration, formal semantics |
| **Stateless (.NET)** | Async patterns, firing modes (immediate vs queued), synchronization context |
| **Erlang gen_statem** | Hot code upgrade, event postponement, multiple timeout types, battle-tested patterns |

### Tier 3: Niche but Informative

| Implementation | Interesting Aspect |
|---|---|
| **QP/C** | MC/DC coverage, trace-based testing, dual-target (host+embedded) |
| **Statig (Rust)** | Compile-time error tests, per-state storage, type-safe hierarchies |
| **USCXML** | Near-complete W3C conformance, transpilation to ANSI-C |
| **Apache Commons SCXML** | W3C test runner infrastructure, tie-breaker tests (6 files!) |

### Critical Test Areas to Prioritize (Based on Research)

Based on what the best implementations test most heavily:

1. **Parallel state semantics** -- Every serious implementation has dedicated parallel tests. XState's is 31KB. Key scenarios:
   - Done detection (all regions final)
   - Fail propagation (any region fails)
   - Re-entering parallel regions
   - Guards in parallel contexts (validation vs regular)
   - Action ordering across regions
   - History in parallel states

2. **History states** -- Boost has 8 edge case tests. XState's history tests are 35KB. Key scenarios:
   - Shallow vs deep history
   - History in parallel states
   - Default history content
   - Inconsistent history configurations

3. **Child machine delegation** -- XState's invoke tests are their largest (91KB). Key scenarios:
   - Sync/async invocation
   - Done/fail/timeout handling
   - Parameter passing
   - Auto-forwarding events
   - Multiple simultaneous invocations
   - Fire-and-forget

4. **Transition priority/resolution** -- Apache Commons has 6 tie-breaker test files. W3C has specific tests. Key scenarios:
   - Document order priority
   - Internal vs external transitions
   - Guarded transition ordering
   - Eventless transition chains

5. **Event processing order** -- XState has microstep, predictableExec, and transient tests. Key scenarios:
   - Run-to-completion semantics
   - Internal event queue processing
   - Macrostep boundaries
   - Entry/exit action ordering

6. **Concurrency/thread safety** -- MassTransit tests deadlocks. Transitions tests threading. Key scenarios:
   - Concurrent event sending
   - Lock contention
   - Deadlock prevention
   - Race conditions in parallel dispatch

7. **Fault handling and recovery** -- MassTransit has 6+ fault test files. Key scenarios:
   - Guard failures
   - Action exceptions
   - Child machine failures
   - Timeout handling
   - Fault correlation

8. **State persistence and restoration** -- XState's rehydration tests (12KB), Spring's persistence tests. Key scenarios:
   - Persist/restore round-trip
   - Restore from arbitrary points
   - Context integrity after restore
   - Parallel state restoration

### Notable Testing Patterns Worth Adopting

1. **W3C-style assertion mapping**: Each test maps to a specific normative assertion in the spec
2. **Trace-based testing** (QP/C): Record the full trace of state transitions and verify the sequence
3. **Property-based verification** (Sismic): Use property statecharts to verify invariants at runtime
4. **Composite event testing** (MassTransit): Test that N events in M regions correctly trigger done
5. **Firing mode testing** (Stateless): Test both immediate and queued execution modes
6. **Mock compatibility testing** (python-statemachine): Ensure machines are testable with standard mocking tools
7. **Memory leak testing** (AASM): Long-running state machine memory safety
8. **Compile-time error testing** (Statig, Boost): Invalid machines should fail early with clear errors
9. **Performance/benchmark testing** (Squirrel): Regression tests for performance characteristics
10. **Tie-breaker testing** (Apache Commons): Exhaustive transition priority resolution tests
