# Exceptions Reference

EventMachine throws specific exceptions for different error categories.
Each exception extends either `LogicException` (developer errors caught at
definition time) or `RuntimeException` (errors that occur during execution).

## Configuration Exceptions

Thrown at definition time when machine config is invalid. These are
`LogicException` subclasses — they indicate a bug in your machine definition,
not a runtime condition.

### InvalidStateConfigException

Thrown by `StateConfigValidator` when machine configuration has structural
errors: invalid keys, wrong state types, conflicting options (machine +
parallel), malformed transitions, cross-region transitions, etc.

- **Extends:** `LogicException`
- **Thrown from:** `StateConfigValidator`, `StateDefinition`, `MachineDefinition`
- **Common causes:** Typos in config keys, final states with transitions, parallel states without regions
- **See:** [Defining States](/building/defining-states), [Writing Transitions](/building/writing-transitions)

### InvalidRouterConfigException

Thrown by `MachineRouter::register()` when endpoint routing options are
inconsistent.

- **Extends:** `LogicException`
- **Thrown from:** `MachineRouter`
- **Common causes:** `only` + `except` together, orphaned `machineIdFor`/`modelFor` refs
- **See:** [Endpoints](/laravel-integration/endpoints)

### InvalidEndpointDefinitionException

Thrown when an endpoint definition references undefined events, outputs,
or invalid actions. Also thrown for forward endpoint conflicts.

- **Extends:** `RuntimeException`
- **Thrown from:** `MachineDefinition`
- **Common causes:** Typo in event type, missing output behavior, forward event collision
- **See:** [Endpoints](/laravel-integration/endpoints)

### InvalidParallelStateDefinitionException

Thrown when parallel state config violates constraints.

- **Extends:** `LogicException`
- **Thrown from:** `MachineDefinition`, `StateConfigValidator`
- **Common causes:** Parallel state without regions, with `initial`, without persistence, without Machine subclass
- **See:** [Parallel States](/advanced/parallel-states/)

### InvalidScheduleDefinitionException

Thrown when a schedule references an undefined event type.

- **Extends:** `RuntimeException`
- **Thrown from:** `MachineDefinition`, `MachineScheduler`
- **See:** [Scheduled Events](/advanced/scheduled-events)

### InvalidListenerDefinitionException

Thrown when listener config uses removed class-as-key format.

- **Extends:** `LogicException`
- **Thrown from:** `StateConfigValidator`
- **See:** [Defining States — Listeners](/building/defining-states#listeners)

### InvalidOutputDefinitionException

Thrown when output is defined on a transient or parallel region state.

- **Extends:** `LogicException`
- **Thrown from:** `StateDefinition`
- **See:** [Outputs](/behaviors/outputs)

### InvalidBehaviorDefinitionException

Thrown for malformed behavior tuples: empty, missing class, closure in tuple.

- **Extends:** `LogicException`
- **Thrown from:** `BehaviorTupleParser`

### MissingBehaviorParameterException

Thrown when a required named parameter is not provided in a behavior tuple.

- **Extends:** `LogicException`
- **Thrown from:** `InvokableBehavior`
- **See:** [Named Parameters](/behaviors/introduction#named-parameters)

### InvalidMachineClassException

Thrown when a job references a machine class that doesn't exist or
doesn't extend `Machine`.

- **Extends:** `LogicException`
- **Thrown from:** `ChildMachineJob`, `SendToMachineJob`
- **See:** [Machine Delegation](/advanced/machine-delegation)

### InvalidJobClassException

Thrown when a job actor class doesn't exist or lacks a `handle()` method.

- **Extends:** `LogicException`
- **Thrown from:** `ChildJobJob`
- **See:** [Job Actors](/advanced/job-actors)

### InvalidTimerDefinitionException

Thrown when a timer duration is zero or negative.

- **Extends:** `LogicException`
- **Thrown from:** `Timer`
- **See:** [Time-Based Events](/advanced/time-based-events)

### MachineDefinitionNotFoundException

Thrown when `definition()` is not implemented on a Machine subclass.

- **Extends:** `RuntimeException`
- **Thrown from:** `Machine`, `MachineScheduler`

### MachineDiscoveryException

Thrown when no valid search paths are found for auto-discovering Machine classes.

- **Extends:** `RuntimeException`
- **Thrown from:** `MachineConfigValidatorCommand`

## Runtime Exceptions

Thrown during machine execution. These indicate conditions that can
legitimately occur at runtime.

### NoTransitionDefinitionFoundException

Thrown when an event has no matching transition in the current state.

- **Extends:** `LogicException`
- **Thrown from:** `MachineDefinition`
- **Caught by:** `SendToMachineJob` (logs warning), `TestMachine` (assertion helpers)
- **See:** [Events](/understanding/events#invalid-events), [Execution Model](/reference/execution-model)

### UndefinedTargetStateException

Thrown when a transition references a target state that doesn't exist
in the machine definition.

- **Extends:** `LogicException`
- **Thrown from:** `TransitionBranch`
- **Previously:** `NoStateDefinitionFoundException`

### MachineValidationException

Thrown when a `ValidationGuardBehavior` fails. Carries Laravel validation
errors. Automatically converted to 422 by endpoint controller.

- **Extends:** `ValidationException`
- **Thrown from:** `Machine`
- **Caught by:** `MachineController` (→ 422), `TestMachine` (assertion helpers)
- **See:** [Validation Guards](/behaviors/validation-guards)

### MachineEventValidationException

Thrown when event payload fails validation defined in `EventBehavior::rules()`.

- **Extends:** `ValidationException`
- **Thrown from:** `EventBehavior`
- **See:** [Events](/behaviors/events)

### MachineContextValidationException

Thrown when machine context fails validation after action execution.

- **Extends:** `ValidationException`
- **Thrown from:** `ContextManager`
- **See:** [Working with Context](/building/working-with-context)

### MaxTransitionDepthExceededException

Thrown when recursive transitions (via `@always` or raised events) exceed
the configured depth limit (default: 100).

- **Extends:** `LogicException`
- **Thrown from:** `MachineDefinition`
- **See:** [Always Transitions](/advanced/always-transitions#infinite-loop-protection)

### MachineAlreadyRunningException

Thrown when a second event is sent to a machine that's already processing.

- **Extends:** `RuntimeException`
- **Thrown from:** `Machine`
- **Caught by:** `SendToMachineJob` (releases back to queue)
- **See:** [Execution Model](/reference/execution-model)

### MachineLockTimeoutException

Thrown when lock acquisition times out during parallel dispatch.

- **Extends:** `RuntimeException`
- **Thrown from:** `MachineLockManager`
- **Caught by:** `Machine` (→ `MachineAlreadyRunningException`), `ListenerJob` (releases)

### NoParentMachineException

Thrown when `sendToParent()` or `dispatchToParent()` is called on a
machine that was not invoked by a parent.

- **Extends:** `RuntimeException`
- **Thrown from:** `InvokableBehavior`
- **See:** [sendTo / sendToParent](/advanced/sendto)

### RestoringStateException

Thrown when machine state cannot be restored from persisted events.

- **Extends:** `RuntimeException`
- **Thrown from:** `Machine`
- **Caught by:** `SendToMachineJob` (logs warning, discards event)

### MissingMachineContextException

Thrown when a behavior accesses a context key that doesn't exist.

- **Extends:** `RuntimeException`
- **Thrown from:** `InvokableBehavior`

### BehaviorNotFoundException

Thrown when a behavior reference cannot be resolved (typo in inline key,
invalid behavior type).

- **Extends:** `RuntimeException`
- **Thrown from:** `ResolvesBehaviors`, `MachineController`

### ArchiveException

Thrown during event archival/restoration: empty collection, compression
failure, decompression failure, invalid data format.

- **Extends:** `RuntimeException`
- **Thrown from:** `MachineEventArchive`, `CompressionManager`
- **See:** [Event Archival](/laravel-integration/archival)

## Testing Exceptions

### BehaviorNotFakedException

Thrown when asserting on a behavior that was never faked via `fake()` or `spy()`.

- **Extends:** `RuntimeException`
- **Thrown from:** `Fakeable` trait
- **See:** [Fakeable Behaviors](/testing/fakeable-behaviors)
