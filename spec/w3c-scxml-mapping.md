# W3C SCXML IRP Test Mapping to EventMachine

> Generated: 2026-03-25
> Total tests: 210 (164 mandatory/auto + 8 mandatory/manual + 5 mandatory/manualAsAuto + 33 optional/auto)
> Applicable: 78 | Partially Applicable: 18 | Not Applicable: 114

---

## Category Legend

- **APPLICABLE**: EventMachine supports the tested concept — needs an equivalent test
- **PARTIALLY-APPLICABLE**: EventMachine supports a variant — needs an adapted test
- **NOT-APPLICABLE**: EventMachine does not support this — documented why

---

## Full Test Categorization

### Executable Content: Raise / Event Ordering (tests 144, 158)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test144 | Events are inserted into the queue in the order in which they are raised (raise foo then bar, foo should come first) | APPLICABLE | EventMachine `raise()` adds events to internal queue in order | Test that multiple `raise()` calls in an action produce events in order |
| test158 | Executable content executes in document order (event1 before event2) | APPLICABLE | EventMachine entry actions execute in array order | Test that entry actions execute in config array order |

### Executable Content: Conditionals (tests 147, 148, 149)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test147 | Only the first true clause in if/elseif/else executes | NOT-APPLICABLE | EventMachine uses PHP guards, not SCXML `<if>/<elseif>/<else>` blocks. Guard logic is per-transition, not inline conditionals | N/A — SCXML conditional element |
| test148 | The else clause executes when if and elseif are false | NOT-APPLICABLE | Same — SCXML `<if>/<elseif>/<else>` not in EventMachine | N/A |
| test149 | Neither if clause executes when all conditions false | NOT-APPLICABLE | Same — SCXML inline conditional element | N/A |

### Executable Content: Foreach (tests 150–156, 525)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test150 | `<foreach>` declares new variable if item doesn't exist | NOT-APPLICABLE | EventMachine has no `<foreach>` element. Iteration is done in PHP action code | N/A |
| test151 | `<foreach>` uses existing var if it exists | NOT-APPLICABLE | Same | N/A |
| test152 | Illegal array/item in foreach causes error.execution | NOT-APPLICABLE | Same | N/A |
| test153 | `<foreach>` iterates array in correct order | NOT-APPLICABLE | Same | N/A |
| test155 | `<foreach>` executes content once per item | NOT-APPLICABLE | Same | N/A |
| test156 | Error in foreach stops execution | NOT-APPLICABLE | Same | N/A |
| test525 | `<foreach>` does a shallow copy (modifying array doesn't change iteration) | NOT-APPLICABLE | Same | N/A |

### Executable Content: Error Handling in Executable Content (test 159)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test159 | Error raised by executable content causes subsequent elements to be skipped | PARTIALLY-APPLICABLE | EventMachine actions throw exceptions; subsequent actions in an entry/exit block may or may not execute depending on error handling | Test that an exception in an entry action prevents subsequent entry actions from executing |

### Send Element: Expression Evaluation (tests 172–176)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test172 | `eventexpr` uses current value of var, not initial | NOT-APPLICABLE | EventMachine has no `<send eventexpr>`. Events are dispatched via `raise()`, `sendTo()`, `dispatchTo()` with explicit event names | N/A — SCXML send element |
| test173 | `targetexpr` uses current value | NOT-APPLICABLE | Same — SCXML `<send targetexpr>` | N/A |
| test174 | `typeexpr` uses current value | NOT-APPLICABLE | Same — SCXML `<send typeexpr>` | N/A |
| test175 | `delayexpr` uses current value | NOT-APPLICABLE | EventMachine timers use `after`/`every` config, not dynamic delay expressions | N/A |
| test176 | `<param>` uses current value of expression | PARTIALLY-APPLICABLE | EventMachine event payloads are computed at dispatch time, similar concept | Test that event payload values are evaluated at send time, not definition time |

### Send Element: Content and Data (tests 179, 205)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test179 | `<content>` populates body of a message | NOT-APPLICABLE | EventMachine events carry payload via EventBehavior, not `<content>` element | N/A |
| test205 | Processor doesn't change the message (event name and data preserved) | APPLICABLE | EventMachine should preserve event name and payload through processing | Test that event name and data are preserved through send/receive cycle |

### Send Element: ID and Delay (tests 183, 185, 186)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test183 | `<send>` stores sendid in idlocation | NOT-APPLICABLE | EventMachine has no `idlocation` attribute on sends | N/A |
| test185 | `<send>` respects delay specification (delayed event arrives after immediate) | PARTIALLY-APPLICABLE | EventMachine has timer-based delayed events (`after` transitions) | Test that timer-based events fire after immediate events |
| test186 | `<send>` evaluates args at send time, not when delay expires | PARTIALLY-APPLICABLE | EventMachine context is captured when event is raised, not when processed | Test that event payload reflects state at raise time |

### Send Element: Child Session Cancellation (test 187)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test187 | Delayed send is not sent if sending session terminates | PARTIALLY-APPLICABLE | EventMachine child machines can be cancelled; pending events should not be delivered | Test that cancelling a child machine prevents its pending events from being delivered |

### Send Element: Internal vs External Queue (tests 189, 190)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test189 | `#_internal` target puts event on internal queue (processed before external) | APPLICABLE | EventMachine has internal event queue (raise) vs external (send) distinction | Test that raise() events are processed before send() events |
| test190 | `#_scxml_sessionid` puts event on external queue | PARTIALLY-APPLICABLE | EventMachine send-to-self goes to external queue | Test that sendTo(self) events go to external queue |

### Send Element: Parent/Child Communication (tests 191, 192)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test191 | `#_parent` target sends event to parent session | APPLICABLE | EventMachine has `sendToParent()` / `dispatchToParent()` | Test that child machine can send events to parent |
| test192 | `#_invokeid` target sends event to specific invoked child | APPLICABLE | EventMachine has `sendTo()` for cross-machine communication | Test that parent can send events to specific child machine |

### Send Element: Errors (tests 194, 198, 199, 200)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test194 | Illegal target causes error.execution | PARTIALLY-APPLICABLE | EventMachine throws exceptions for invalid sendTo targets | Test that sending to non-existent machine raises exception |
| test198 | Default send type uses SCXML event I/O processor | NOT-APPLICABLE | EventMachine has no I/O processor concept; uses Laravel queues | N/A |
| test199 | Invalid send type results in error.execution | NOT-APPLICABLE | EventMachine has no send type attribute | N/A |
| test200 | Processor supports SCXML event I/O processor | NOT-APPLICABLE | SCXML-specific I/O processor concept | N/A |

### Cancel Element (tests 207, 208, 210)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test207 | Cannot cancel an event in another session | PARTIALLY-APPLICABLE | EventMachine timer cancellation is scoped to the machine instance | Test that one machine cannot cancel another machine's timers |
| test208 | Cancel works (delayed event1 cancelled, event2 arrives) | APPLICABLE | EventMachine supports timer cancellation | Test that cancelling a timer prevents its event from firing |
| test210 | `sendidexpr` works with cancel (uses current value) | NOT-APPLICABLE | EventMachine has no `sendidexpr` — timers are cancelled by state exit, not by ID expression | N/A |

### Invoke: Basics and Type (tests 215, 216, 216sub1, 220)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test215 | `typeexpr` evaluated at runtime for invoke | NOT-APPLICABLE | EventMachine machine delegation uses static `machine` key, not dynamic type expressions | N/A |
| test216 | `srcexpr` evaluated at runtime for invoke | NOT-APPLICABLE | Same — no dynamic source expressions | N/A |
| test216sub1 | Sub-process that terminates immediately (helper) | NOT-APPLICABLE | Helper file | N/A |
| test220 | SCXML type is supported for invoke | PARTIALLY-APPLICABLE | EventMachine supports machine delegation (child machines) | Test that machine delegation works (child machine invocation) |

### Invoke: ID Management (tests 223, 224, 225)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test223 | `idlocation` is supported for invoke | NOT-APPLICABLE | EventMachine uses `$context->machineId()` not idlocation | N/A |
| test224 | Auto-generated invoke ID has form `stateid.platformid` | NOT-APPLICABLE | EventMachine generates UUIDs for machine IDs, different format | N/A |
| test225 | Auto-generated invoke ID is unique | APPLICABLE | EventMachine child machine IDs must be unique | Test that each delegated child machine gets a unique ID |

### Invoke: Data Passing (tests 226, 226sub1, 240, 241, 243, 244, 245, 276, 276sub1)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test226 | Data can be passed to invoked process via param | APPLICABLE | EventMachine passes context to child machines | Test that parent can pass context data to child machine |
| test226sub1 | Helper sub-process for test226 | NOT-APPLICABLE | Helper file | N/A |
| test240 | Datamodel values specified by namelist and param | NOT-APPLICABLE | EventMachine has no namelist concept; uses context | N/A |
| test241 | Param and namelist produce same results | NOT-APPLICABLE | Same | N/A |
| test243 | Datamodel values specified by param | APPLICABLE | EventMachine passes context to child via delegation config | Test param passing to child machine |
| test244 | Datamodel values specified by namelist | NOT-APPLICABLE | No namelist in EventMachine | N/A |
| test245 | Non-existent datamodel values not set in child | PARTIALLY-APPLICABLE | EventMachine child context is independent; undefined parent context keys shouldn't pollute child | Test that child machine doesn't receive undefined parent context |
| test276 | Values from parent override child defaults | APPLICABLE | EventMachine child machine context can be initialized by parent | Test that parent context values override child defaults |
| test276sub1 | Helper sub-process for test276 | NOT-APPLICABLE | Helper file | N/A |

### Invoke: Communication (tests 228, 229, 232, 233, 234, 235, 236, 237)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test228 | Invokeid included in events from invoked process | PARTIALLY-APPLICABLE | EventMachine tracks child machine IDs in `machine_children` table | Test that events from child carry child machine identifier |
| test229 | Autoforward works (events forwarded to child) | PARTIALLY-APPLICABLE | EventMachine has `forward` key for HTTP route forwarding, not automatic event forwarding | Test forward key behavior for child machine delegation |
| test232 | Parent receives multiple events from child | APPLICABLE | EventMachine child can send multiple events to parent | Test that parent receives all events sent by child |
| test233 | Finalize markup runs before event is processed | NOT-APPLICABLE | EventMachine has no `<finalize>` element | N/A |
| test234 | Only finalize in invoking state runs | NOT-APPLICABLE | Same | N/A |
| test235 | done.invoke.id has correct ID | APPLICABLE | EventMachine generates `@done` event when child completes | Test that @done event identifies the completing child |
| test236 | done.invoke.id is last event from child | APPLICABLE | EventMachine @done should be the last event from a completed child | Test @done ordering relative to child's exit events |
| test237 | Cancelling invoke works (child terminated when parent exits state) | APPLICABLE | EventMachine cancels child when parent leaves delegating state | Test that child is cancelled when parent transitions away |

### Invoke: Content/Source (tests 239, 239sub1, 242, 242sub1, 530)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test239 | Markup can be specified by src and by content | NOT-APPLICABLE | EventMachine uses PHP class references for child machines, not XML src/content | N/A |
| test239sub1 | Helper | NOT-APPLICABLE | Helper file | N/A |
| test242 | src and content produce same behavior | NOT-APPLICABLE | Same | N/A |
| test242sub1 | Helper | NOT-APPLICABLE | Helper file | N/A |
| test530 | `<content>` child evaluated when invoke executes | NOT-APPLICABLE | Same | N/A |

### Invoke: Lifecycle (tests 247, 252, 253, 554)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test247 | done.invoke is received when child terminates | APPLICABLE | EventMachine @done fires when child completes | Test that @done event fires when child machine reaches final state |
| test252 | Events from cancelled child not processed | APPLICABLE | EventMachine should not process events from cancelled children | Test that parent ignores events from cancelled child |
| test253 | SCXML event processor used in both directions | NOT-APPLICABLE | SCXML I/O processor concept | N/A |
| test554 | Error in invoke args cancels invocation | PARTIALLY-APPLICABLE | EventMachine should handle invalid child machine config gracefully | Test that invalid child machine config prevents delegation |

### Data Model: Initialization (tests 277, 279, 280, 550, 551, 552)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test277 | Illegal initial value creates unbound variable | NOT-APPLICABLE | EventMachine uses PHP typed context, no "unbound" concept | N/A |
| test279 | Early binding: vars assigned before state is visited | APPLICABLE | EventMachine context is initialized at machine start (early binding) | Test that context values are available from initial state regardless of where defined |
| test280 | Late binding: var not bound until state entered | NOT-APPLICABLE | EventMachine always uses early binding (context initialized at start) | N/A |
| test550 | expr assigns value with early binding | APPLICABLE | EventMachine context initial values | Test that context initial values are set correctly |
| test551 | Inline content assigns value | NOT-APPLICABLE | EventMachine uses PHP expressions for context values, not inline XML content | N/A |
| test552 | src content assigns value from file | NOT-APPLICABLE | EventMachine doesn't load context from files | N/A |

### Data Model: Assignment (tests 286, 287, 311, 312, 487)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test286 | Assignment to non-declared var causes error | APPLICABLE | EventMachine ContextManager validates context keys | Test that assigning to undeclared context key raises error |
| test287 | Legal value can be assigned to valid location | APPLICABLE | EventMachine context assignment | Test that context values can be updated in calculators/actions |
| test311 | Assignment to non-existent location yields error | APPLICABLE | Same as test286 | Test invalid context key assignment |
| test312 | Assignment with illegal expression raises error | NOT-APPLICABLE | EventMachine uses PHP expressions which throw standard PHP errors | N/A — PHP handles this natively |
| test487 | Illegal assignment raises error.execution | APPLICABLE | Same concept as test286/311 | Test context assignment validation |

### Data Model: DoneData (tests 294, 298, 527, 528, 529)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test294 | Param inside donedata ends up in done event data | APPLICABLE | EventMachine @done events can carry data from child's final state | Test that @done event carries data from child's context |
| test298 | Non-existent location in donedata param raises error | PARTIALLY-APPLICABLE | EventMachine should handle invalid context references in done data | Test error handling for invalid context refs in done routing |
| test527 | expr works with `<content>` in donedata | NOT-APPLICABLE | EventMachine doesn't use XML content elements | N/A |
| test528 | Illegal expr in donedata content produces error | NOT-APPLICABLE | Same | N/A |
| test529 | Children work with `<content>` in donedata | NOT-APPLICABLE | Same | N/A |

### Data Model: Script Element (tests 302, 303, 304)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test302 | Script evaluated at load time | NOT-APPLICABLE | EventMachine has no `<script>` element; uses PHP classes | N/A |
| test303 | Scripts run as part of executable content | NOT-APPLICABLE | Same | N/A |
| test304 | Variable declared by script accessible in data model | NOT-APPLICABLE | Same | N/A |

### Data Model: Expressions and In() Predicate (tests 309, 310, 344)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test309 | Expression that can't be interpreted as boolean treated as false | NOT-APPLICABLE | EventMachine guards return PHP booleans; PHP handles truthy/falsy natively | N/A |
| test310 | Simple test of in() predicate (checks if in parallel sibling state) | APPLICABLE | EventMachine has parallel state awareness; guards can check current state | Test that a guard can check if a parallel sibling region is in a specific state |
| test344 | Invalid cond expression evaluates to false and raises error | NOT-APPLICABLE | EventMachine guards are PHP code, not expression strings | N/A |

### Data Model: Param Errors (tests 343, 488)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test343 | Illegal param produces error.execution and empty event.data | NOT-APPLICABLE | EventMachine events use PHP-typed payloads; param concept differs | N/A |
| test488 | Illegal expr in param produces error.execution | NOT-APPLICABLE | Same | N/A |

### System Variables: _event (tests 318, 319, 330, 331, 396)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test318 | _event stays bound during onexit and entry into next state | APPLICABLE | EventMachine `triggeringEvent` persists across macrostep (entry actions, @always chains) | Test that triggeringEvent is available in exit and entry actions |
| test319 | _event is not bound before any event has been raised | APPLICABLE | EventMachine should not have a triggering event before first event | Test that triggeringEvent is null before first event |
| test330 | Required fields present in internal and external events | PARTIALLY-APPLICABLE | EventMachine events have name/type/data; some SCXML fields (sendid, origin, origintype, invokeid) don't map directly | Test that EventBehavior has required fields (name, payload) |
| test331 | _event.type set correctly for internal, platform, external events | PARTIALLY-APPLICABLE | EventMachine distinguishes internal (raise) vs external events | Test event type field for raised vs sent events |
| test396 | _event.name matches event name used for transition matching | APPLICABLE | EventMachine triggeringEvent name should match the transition event | Test that triggeringEvent name matches the transition's event type |

### System Variables: _event Fields (tests 332, 333, 335, 336, 337, 338, 339, 342)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test332 | sendid present in error events from send errors | NOT-APPLICABLE | EventMachine has no sendid concept | N/A |
| test333 | sendid blank in non-error events | NOT-APPLICABLE | Same | N/A |
| test335 | Origin field blank for internal events | NOT-APPLICABLE | EventMachine has no origin field on events | N/A |
| test336 | Origin field of external event contains return URL | NOT-APPLICABLE | Same | N/A |
| test337 | origintype blank on internal events | NOT-APPLICABLE | Same | N/A |
| test338 | invokeid set correctly in events from invoked process | PARTIALLY-APPLICABLE | EventMachine tracks which child sent an event via machine_children | Test that events from child machines carry child identification |
| test339 | invokeid blank when event wasn't from invoked process | NOT-APPLICABLE | EventMachine doesn't have invokeid field | N/A |
| test342 | eventexpr sets event name | NOT-APPLICABLE | EventMachine has no eventexpr; event names are string constants | N/A |

### System Variables: _sessionid (tests 321, 322)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test321 | _sessionid is bound on startup | APPLICABLE | EventMachine `$context->machineId()` is available from start | Test that machineId is available immediately after machine creation |
| test322 | _sessionid remains same value throughout session | APPLICABLE | EventMachine machineId is immutable | Test that machineId does not change across transitions |

### System Variables: _name (tests 323, 324)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test323 | _name is bound on startup | APPLICABLE | EventMachine machine definition `id` is always available | Test that machine ID (definition name) is available from start |
| test324 | _name cannot be assigned to | APPLICABLE | EventMachine definition ID is immutable | Test that machine definition ID cannot be changed |

### System Variables: _ioprocessors (tests 325, 326)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test325 | _ioprocessors bound at startup | NOT-APPLICABLE | EventMachine has no I/O processor registry | N/A |
| test326 | _ioprocessors stays bound and cannot be assigned | NOT-APPLICABLE | Same | N/A |

### System Variables: Immutability (tests 329, 346)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test329 | None of system variables can be modified | APPLICABLE | EventMachine machineId, parentMachineId should be immutable | Test that system-level context values cannot be overwritten |
| test346 | Attempt to change system variable causes error.execution | APPLICABLE | Same concept | Test that attempting to modify machineId raises error |

### SCXML Event I/O Processor (tests 347, 348, 349, 350, 351, 352, 354, 495, 496, 500, 501, 521, 553)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test347 | SCXML event I/O processor works for parent-child communication | APPLICABLE | EventMachine parent-child communication via sendTo/sendToParent | Test bidirectional parent-child event exchange |
| test348 | Event param of send sets event name | APPLICABLE | EventMachine event type name matching | Test that sent event has correct name |
| test349 | Origin field value can send event back to sender | NOT-APPLICABLE | EventMachine has no origin-based reply mechanism | N/A |
| test350 | Target value selects which session gets event | APPLICABLE | EventMachine `sendTo()` targets specific machine by ID | Test that sendTo delivers to correct machine instance |
| test351 | sendid set in event if present in send | NOT-APPLICABLE | EventMachine has no sendid concept | N/A |
| test352 | origintype is SCXML Event Processor URI | NOT-APPLICABLE | SCXML-specific | N/A |
| test354 | event.data populated via namelist, param, and content | NOT-APPLICABLE | EventMachine uses EventBehavior for payloads, not SCXML data elements | N/A |
| test495 | SCXML I/O processor puts events in correct queues | APPLICABLE | EventMachine internal vs external queue distinction (raise vs send) | Test that raise goes to internal queue, send goes to external |
| test496 | Send to non-existent session via I/O processor raises error | APPLICABLE | EventMachine should error when sending to non-existent machine | Test error when sendTo targets non-existent machine |
| test500 | Location field found in SCXML Event I/O processor entry | NOT-APPLICABLE | SCXML _ioprocessors concept | N/A |
| test501 | Location entry can be used as target for event | NOT-APPLICABLE | Same | N/A |
| test521 | Processor raises error.communication for undispatchable event | APPLICABLE | EventMachine should raise error for undeliverable events | Test error handling for undeliverable cross-machine events |
| test553 | Send not dispatched if arg evaluation causes error | PARTIALLY-APPLICABLE | EventMachine should not dispatch event if payload construction fails | Test that event is not sent if event construction fails |

### Core: Initial State (tests 355, 364, 413, 576)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test355 | Default initial state is first in document order | APPLICABLE | EventMachine uses `initial` key; first state if not specified | Test default initial state selection |
| test364 | Default initial states entered when compound state entered (initial attribute, initial element, first child) | APPLICABLE | EventMachine supports `initial` config on compound states | Test that entering compound state enters its initial substate |
| test413 | Machine enters configuration specified by initial element | APPLICABLE | EventMachine parallel initial state configuration | Test that machine enters correct initial parallel configuration |
| test576 | Initial value of scxml respected for deeply nested parallel siblings | APPLICABLE | EventMachine initial config for nested parallel regions | Test deeply nested initial state with parallel regions |

### Core: State Entry/Exit (tests 375, 376, 377, 378, 407)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test375 | Onentry handlers execute in document order | APPLICABLE | EventMachine entry actions execute in array order | Test that multiple entry actions run in defined order |
| test376 | Each onentry handler is a separate block (error in one doesn't prevent others) | PARTIALLY-APPLICABLE | EventMachine entry actions: depends on error handling strategy | Test that error in one entry action doesn't prevent independent entry actions |
| test377 | Onexit handlers execute in document order | APPLICABLE | EventMachine exit actions execute in array order | Test that multiple exit actions run in defined order |
| test378 | Each onexit handler is a separate block | PARTIALLY-APPLICABLE | Same as test376 for exit actions | Test error isolation in exit actions |
| test407 | Simple onexit handler works (var incremented on exit) | APPLICABLE | EventMachine exit actions modify context | Test that exit actions execute and can modify context |

### Core: Final States and done.state (tests 372, 416, 417, 570)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test372 | Entering final state generates done.state.parentid after onentry | APPLICABLE | EventMachine @done fires after entry actions of final state | Test @done event timing relative to entry actions |
| test416 | done.state.id generated when entering final state of compound state | APPLICABLE | EventMachine @done for compound state completion | Test that @done fires when final state entered in compound state |
| test417 | done.state.id when all parallel children in final states | APPLICABLE | EventMachine @done fires when all parallel regions complete | Test @done for parallel state completion |
| test570 | done.state.id generated when all parallel children final | APPLICABLE | Same as test417 | Test parallel @done generation |

### Core: History States (tests 387, 388, 579, 580)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test387 | Default history state works (shallow and deep) | NOT-APPLICABLE | EventMachine does NOT implement history states | N/A — No history states |
| test388 | History states work correctly (deep and shallow) | NOT-APPLICABLE | Same | N/A |
| test579 | Default history content executed correctly | NOT-APPLICABLE | Same | N/A |
| test580 | History state never part of configuration | NOT-APPLICABLE | Same | N/A |

### Core: Event Processing Order (tests 396, 399, 401, 402, 409, 411, 412, 419, 421, 422, 423)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test396 | _event.name matches event name for transition matching | APPLICABLE | (Duplicate of system variables section — included here for grouping) | Test event name matching |
| test399 | Event name matching with prefix matching and multiple event designators | APPLICABLE | EventMachine supports event matching by type | Test event name prefix matching |
| test401 | Errors go to internal queue (processed before external events) | APPLICABLE | EventMachine internal events (from raise/errors) processed before external | Test that internal error events take priority over external events |
| test402 | Errors pulled off internal queue in order with prefix matching | APPLICABLE | EventMachine error event ordering | Test error event queue ordering |
| test409 | States removed from active list as exited (in() predicate reflects exit) | APPLICABLE | EventMachine state configuration is updated during exit | Test that state is no longer active during exit handler execution |
| test411 | States added to active list as entered (before onentry) | APPLICABLE | EventMachine state is active when its entry handler runs | Test that state is in configuration when its entry action runs |
| test412 | Executable content in initial transition runs after parent onentry, before child onentry | APPLICABLE | EventMachine entry ordering: parent entry → initial transition → child entry | Test entry action ordering across state hierarchy |
| test419 | Eventless transitions take precedence over event-driven ones | APPLICABLE | EventMachine @always transitions take precedence | Test that @always transitions fire before event-driven transitions |
| test421 | Internal events take priority over external; processor keeps pulling internal events | APPLICABLE | EventMachine processes all internal events before external | Test internal event priority and exhaustion |
| test422 | At end of macrostep, invokes execute in entered states | PARTIALLY-APPLICABLE | EventMachine machine delegation starts after macrostep | Test that child machine delegation starts after macrostep completion |
| test423 | External events pulled until one matches a transition | APPLICABLE | EventMachine discards unmatched external events | Test that unmatched external events are consumed |

### Core: Transition Selection (tests 403a, 403b, 403c, 404, 405, 406)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test403a | Optimal enablement: child transitions preferred over parent, document order breaks ties | APPLICABLE | EventMachine: child state transitions take priority; order in config breaks ties | Test transition priority: child over parent, config order |
| test403b | Optimally enabled set is a set (transition taken only once even if enabled in multiple states) | APPLICABLE | EventMachine parallel regions: transition should be taken once | Test transition deduplication in parallel states |
| test403c | Preemption works correctly in optimally enabled set | APPLICABLE | EventMachine transition preemption | Test that a higher-priority transition preempts lower ones |
| test404 | States exited in exit order (children before parents, reverse doc order) | APPLICABLE | EventMachine exits children before parents | Test exit ordering: children first, reverse config order |
| test405 | Executable content in transitions runs after states exited | APPLICABLE | EventMachine transition actions run after exit actions | Test that transition actions execute after exit actions |
| test406 | States entered in entry order (parents before children, document order) | APPLICABLE | EventMachine enters parents before children | Test entry ordering: parents first, config order |

### Core: Internal vs External Transitions (tests 503, 504, 505, 506, 533)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test503 | Targetless transition does not exit/reenter source state | APPLICABLE | EventMachine targetless transitions should not trigger exit/entry | Test that targetless transition doesn't cause exit/entry |
| test504 | External transition exits all states up to LCCA | APPLICABLE | EventMachine external transitions exit to least common compound ancestor | Test external transition exit scope |
| test505 | Internal transition does not exit source state | APPLICABLE | EventMachine internal transitions don't exit source | Test internal transition behavior |
| test506 | Internal transition whose targets not proper descendants behaves like external | APPLICABLE | EventMachine should treat such transitions as external | Test internal transition edge case |
| test533 | Internal transition from non-compound source exits source state | APPLICABLE | EventMachine: internal transition from atomic/parallel source exits it | Test internal transition from non-compound state |

### Mandatory/Manual Tests

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test178 | Multiple key/value pairs included in send, even with same keys | NOT-APPLICABLE | SCXML send element specifics; manual test | N/A |
| test230 | Autoforwarded event has same fields as original | PARTIALLY-APPLICABLE | EventMachine forward key: forwarded events should preserve data | Test that forwarded events preserve original data |
| test250 | Onexit handlers run when invoked process cancelled | APPLICABLE | EventMachine: exit actions should run when child is cancelled | Test that exit actions run on child cancellation |
| test301 | Processor rejects document with undownloadable script | NOT-APPLICABLE | EventMachine has no `<script src>` element | N/A |
| test307 | Late binding: accessing undeclared var behavior | NOT-APPLICABLE | EventMachine uses early binding only | N/A |
| test313 | Illegal expression handling (manual) | NOT-APPLICABLE | EventMachine uses PHP; handled natively | N/A |
| test314 | Error not raised until illegal expr evaluated | NOT-APPLICABLE | Same | N/A |
| test415 | Machine halts on entering top-level final state | APPLICABLE | EventMachine should halt when reaching top-level final state | Test that machine halts at top-level final state |

### Mandatory/ManualAsAuto Tests

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test230a | Autoforwarded event preserves fields (auto version) | PARTIALLY-APPLICABLE | Same as test230 | Test forward event preservation |
| test307a | Late binding variable access (auto version) | NOT-APPLICABLE | EventMachine uses early binding | N/A |
| test313a | Illegal expression handling (auto version) | NOT-APPLICABLE | PHP handles natively | N/A |
| test314a | Error timing for illegal expr (auto version) | NOT-APPLICABLE | Same | N/A |
| test415a | Invoked machine halts at top-level final | APPLICABLE | EventMachine child machine should halt at final state | Test child machine halts at final state |

### Optional/Auto: Basic Tests

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test193 | Omitting target of send puts event on external queue | APPLICABLE | EventMachine send without explicit target | Test default send target behavior |
| test201 | Processor supports basic HTTP event I/O processor | NOT-APPLICABLE | EventMachine doesn't implement BasicHTTP I/O processor | N/A |
| test278 | Variable accessible from outside its lexical scope | APPLICABLE | EventMachine context is globally scoped within machine | Test that context is accessible from any state |

### Optional/Auto: ECMAScript-Specific (tests 444–460)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test444 | `<data>` creates new ECMAScript variable | NOT-APPLICABLE | ECMAScript datamodel specific | N/A |
| test445 | ECMAScript objects undefined if data doesn't assign value | NOT-APPLICABLE | ECMAScript specific | N/A |
| test446 | JSON child of data assigned as value | NOT-APPLICABLE | ECMAScript specific | N/A |
| test448 | All ECMAScript objects in single global scope | NOT-APPLICABLE | ECMAScript specific | N/A |
| test449 | ECMAScript objects converted to booleans in cond | NOT-APPLICABLE | ECMAScript specific | N/A |
| test451 | in() predicate (ECMAScript version) | NOT-APPLICABLE | ECMAScript-specific test of in() — already covered by test310 | N/A |
| test452 | Can assign to substructure of variable | NOT-APPLICABLE | ECMAScript specific (object property assignment) | N/A |
| test453 | Any ECMAScript expression as value (function assignment) | NOT-APPLICABLE | ECMAScript specific | N/A |
| test456 | Script element can update data model | NOT-APPLICABLE | ECMAScript script element | N/A |
| test457 | Legal iterable collections are arrays (ECMAScript foreach) | NOT-APPLICABLE | ECMAScript specific | N/A |
| test459 | foreach order in ECMAScript | NOT-APPLICABLE | ECMAScript specific | N/A |
| test460 | foreach shallow copy in ECMAScript | NOT-APPLICABLE | ECMAScript specific | N/A |

### Optional/Auto: ECMAScript Data Model (tests 557, 558, 560–562, 569, 578)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test557 | XML child of data assigned as value in ECMA model | NOT-APPLICABLE | ECMAScript XML handling | N/A |
| test558 | Non-XML child of data treated as string in ECMA model | NOT-APPLICABLE | ECMAScript specific | N/A |
| test560 | Processor creates correct structure in _event.data for KVPs | NOT-APPLICABLE | ECMAScript event data structure | N/A |
| test561 | Processor creates ECMAScript DOM for XML in event | NOT-APPLICABLE | ECMAScript specific | N/A |
| test562 | Processor creates space-normalized string for other event data | NOT-APPLICABLE | ECMAScript specific | N/A |
| test569 | Location field in SCXML Event I/O processor (ECMAScript) | NOT-APPLICABLE | ECMAScript specific | N/A |
| test578 | Processor creates ECMAScript object for JSON in event | NOT-APPLICABLE | ECMAScript specific | N/A |

### Optional/Auto: BasicHTTP Event I/O Processor (tests 509, 510, 518–520, 522, 531, 532, 534, 567, 577)

| Test ID | Description | Category | Reason | EventMachine Mapping |
|---------|-------------|----------|--------|---------------------|
| test509 | BasicHTTP uses POST method | NOT-APPLICABLE | EventMachine doesn't implement BasicHTTP I/O processor | N/A |
| test510 | BasicHTTP messages go to external queue | NOT-APPLICABLE | Same | N/A |
| test518 | Namelist values encoded as POST parameters | NOT-APPLICABLE | Same | N/A |
| test519 | Param values encoded as POST parameters | NOT-APPLICABLE | Same | N/A |
| test520 | Content sent as body of HTTP message | NOT-APPLICABLE | Same | N/A |
| test522 | Location entry for BasicHTTP can send message | NOT-APPLICABLE | Same | N/A |
| test531 | _scxmleventname param used as event name | NOT-APPLICABLE | Same | N/A |
| test532 | HTTP method name used as event name if no _scxmleventname | NOT-APPLICABLE | Same | N/A |
| test534 | Send event value sent as _scxmleventname param | NOT-APPLICABLE | Same | N/A |
| test567 | Content other than _scxmleventname populates _event.data | NOT-APPLICABLE | Same | N/A |
| test577 | Send without target in BasicHTTP causes error.communication | NOT-APPLICABLE | Same | N/A |

---

## Summary by Category

### APPLICABLE (78 tests → need EventMachine tests)

| Group | Test IDs | Count |
|-------|----------|-------|
| Event ordering / raise | 144, 158 | 2 |
| Event processing order | 396, 399, 401, 402, 409, 411, 412, 419, 421, 423 | 10 |
| Transition selection | 403a, 403b, 403c, 404, 405, 406 | 6 |
| Internal/external transitions | 503, 504, 505, 506, 533 | 5 |
| Initial state | 355, 364, 413, 576 | 4 |
| Entry/exit actions | 375, 377, 407 | 3 |
| Final states / @done | 372, 416, 417, 570 | 4 |
| System variables | 318, 319, 321, 322, 323, 324, 329, 346, 396 | 9 |
| Event fields | 205, 348 | 2 |
| Internal vs external queue | 189, 495 | 2 |
| Parent-child communication | 191, 192, 347, 350 | 4 |
| Error handling | 286, 287, 311, 487, 496, 521 | 6 |
| Invoke lifecycle | 225, 226, 232, 235, 236, 237, 243, 247, 250, 252, 276 | 11 |
| Invoke done event | 294 | 1 |
| Timer cancellation | 208 | 1 |
| Data model | 279, 550 | 2 |
| Parallel done | 403b, 417, 570 | (already counted) |
| State activity during entry/exit | 409, 411 | (already counted) |
| Machine halt at final | 415, 415a | 2 |
| Optional applicable | 193, 278 | 2 |
| Eventless priority | 419 | (already counted) |
| Send target | 348, 350 | (already counted) |

**Deduplicated total: 78**

### PARTIALLY-APPLICABLE (18 tests → need adapted tests)

| Test IDs | Concept |
|----------|---------|
| 159 | Error halts subsequent executable content |
| 176 | Event payload evaluated at dispatch time |
| 185 | Timer-based delayed events |
| 186 | Context captured at raise time |
| 187 | Child cancellation prevents pending events |
| 190 | Send-to-self goes to external queue |
| 194 | Error for invalid send target |
| 207 | Cross-machine timer isolation |
| 220 | Machine delegation basics |
| 228 | Child machine identification in events |
| 229, 230, 230a | Forward event preservation |
| 245 | Child context isolation |
| 298 | Invalid context refs in done routing |
| 330 | Event required fields |
| 331 | Event type for internal vs external |
| 338 | Child machine identification |
| 376, 378 | Error isolation in entry/exit blocks |
| 422 | Delegation starts after macrostep |
| 553 | Event not sent if construction fails |
| 554 | Invalid child config prevents delegation |

**Total: 18**

### NOT-APPLICABLE (114 tests — documented reasons)

| Reason | Count | Test IDs |
|--------|-------|----------|
| SCXML `<if>/<elseif>/<else>` element | 3 | 147, 148, 149 |
| SCXML `<foreach>` element | 7 | 150, 151, 152, 153, 155, 156, 525 |
| SCXML `<send>` expression attributes (eventexpr, targetexpr, typeexpr, delayexpr) | 4 | 172, 173, 174, 175 |
| SCXML `<send>` content/idlocation | 2 | 179, 183 |
| SCXML send type/I/O processor concept | 4 | 198, 199, 200, 210 |
| SCXML `<invoke>` attributes (typeexpr, srcexpr, idlocation, src, content) | 8 | 215, 216, 216sub1, 223, 224, 239, 239sub1, 242, 242sub1, 530 |
| SCXML namelist concept | 3 | 240, 241, 244 |
| SCXML `<finalize>` element | 2 | 233, 234 |
| SCXML I/O processor concept | 7 | 325, 326, 349, 351, 352, 354, 500, 501 |
| SCXML event fields (sendid, origin, origintype, invokeid) | 6 | 332, 333, 335, 336, 337, 339 |
| SCXML eventexpr | 1 | 342 |
| SCXML param errors | 2 | 343, 488 |
| SCXML script element | 3 | 302, 303, 304 |
| SCXML expression evaluation semantics | 3 | 309, 312, 344 |
| SCXML data initialization (late binding, inline content, src) | 4 | 277, 280, 551, 552 |
| SCXML donedata content element | 3 | 527, 528, 529 |
| SCXML `<invoke>` concept (no invokeid concept) | 1 | 253 |
| History states not implemented | 4 | 387, 388, 579, 580 |
| BasicHTTP I/O Processor not implemented | 11 | 201, 509, 510, 518, 519, 520, 522, 531, 532, 534, 567, 577 |
| ECMAScript datamodel specific | 18 | 444, 445, 446, 448, 449, 451, 452, 453, 456, 457, 459, 460, 557, 558, 560, 561, 562, 569, 578 |
| Manual test (SCXML specific) | 5 | 178, 301, 307, 307a, 313, 313a, 314, 314a |
| Helper sub-files | 5 | 216sub1, 226sub1, 239sub1, 242sub1, 276sub1 |

**Total: 114**

---

## Grouping by EventMachine Test Category

### Group 1: Initial State and State Configuration (8 tests)
Tests: 355, 364, 413, 576, 279, 550, 278, 415, 415a

Core concept: Machine enters correct initial state, context initialized correctly.

### Group 2: Transition Selection and Priority (11 tests)
Tests: 403a, 403b, 403c, 404, 405, 406, 419, 503, 504, 505, 506, 533

Core concept: Transition priority (child over parent), internal vs external, optimal enablement, preemption.

### Group 3: Entry/Exit Action Ordering (8 tests)
Tests: 375, 376, 377, 378, 407, 412, 159

Core concept: Entry/exit actions execute in order, error isolation between blocks.

### Group 4: Event Processing and Queue Semantics (10 tests)
Tests: 144, 158, 189, 193, 396, 399, 401, 402, 421, 423

Core concept: Internal queue before external, event ordering, prefix matching, eventless priority.

### Group 5: Parallel States (6 tests)
Tests: 310, 403b, 417, 570, 409, 411

Core concept: Parallel region entry/exit, done detection, in() predicate.

### Group 6: Final States and @done (6 tests)
Tests: 294, 372, 416, 417, 570, 415, 415a

Core concept: @done event generation, timing, data passing.

### Group 7: Machine Delegation / Invoke (16 tests)
Tests: 220, 225, 226, 228, 229, 230, 230a, 232, 235, 236, 237, 243, 245, 247, 250, 252, 276, 338, 422, 554

Core concept: Child machine invocation, communication, cancellation, done events.

### Group 8: Cross-Machine Communication / Send (10 tests)
Tests: 176, 185, 186, 187, 190, 191, 192, 194, 347, 348, 350, 495, 496, 521, 553

Core concept: sendTo, sendToParent, raise, event delivery, error handling.

### Group 9: Timer and Cancel (3 tests)
Tests: 207, 208, 185

Core concept: Timer cancellation, delayed events.

### Group 10: System Variables and Event Fields (12 tests)
Tests: 205, 318, 319, 321, 322, 323, 324, 329, 330, 331, 346, 396

Core concept: triggeringEvent, machineId, event immutability.

### Group 11: Data Model / Context (6 tests)
Tests: 279, 286, 287, 298, 311, 487, 550

Core concept: Context initialization, assignment validation.

---

## Test-Writing Bead Plan

Each bead below represents a focused test-writing task for one EventMachine test category inspired by W3C SCXML IRP tests.

### Bead 1: W3C Initial State and Configuration Tests
Write tests for: default initial state (test355), compound initial (test364), parallel initial (test413, test576), early binding context (test279, test550), cross-scope access (test278), machine halt at final (test415, test415a).
**Estimated tests: 8**

### Bead 2: W3C Transition Selection and Priority Tests
Write tests for: child-over-parent priority (test403a), transition set uniqueness (test403b), preemption (test403c), exit ordering (test404), transition action timing (test405), entry ordering (test406), eventless priority (test419), targetless (test503), external LCCA (test504), internal (test505), internal edge cases (test506, test533).
**Estimated tests: 12**

### Bead 3: W3C Entry/Exit Action Ordering Tests
Write tests for: entry action order (test375), entry error isolation (test376), exit action order (test377), exit error isolation (test378), exit action execution (test407), initial transition ordering (test412), error halts subsequent content (test159).
**Estimated tests: 7**

### Bead 4: W3C Event Processing and Queue Semantics Tests
Write tests for: raise ordering (test144, test158), internal before external (test189, test193, test495), event name matching (test396, test399), error events in internal queue (test401, test402), internal event exhaustion (test421), external event consumption (test423).
**Estimated tests: 10**

### Bead 5: W3C Parallel State Tests
Write tests for: in() predicate in parallel (test310), parallel transition dedup (test403b), parallel done detection (test417, test570), state active during entry (test411), state inactive during exit (test409).
**Estimated tests: 6**

### Bead 6: W3C Final State and @done Tests
Write tests for: @done after entry actions (test372), compound @done (test416), parallel @done (test417, test570), @done data (test294), machine halt at final (test415, test415a).
**Estimated tests: 6**

### Bead 7: W3C Machine Delegation (Invoke) Tests
Write tests for: delegation basics (test220), unique child IDs (test225), context passing (test226, test243, test276), child identification (test228, test338), forward event preservation (test229, test230, test230a), multiple child events (test232), @done identification (test235), @done ordering (test236), child cancellation (test237, test250, test252), child context isolation (test245), invalid config (test554), delegation after macrostep (test422).
**Estimated tests: 16**

### Bead 8: W3C Cross-Machine Communication Tests
Write tests for: payload evaluation timing (test176, test186), delayed events (test185), child cancellation prevents events (test187), send-to-self queue (test190), parent-child send (test191, test192, test347), target selection (test348, test350), invalid target (test194, test496, test521), internal vs external queue (test495), event construction failure (test553).
**Estimated tests: 12**

### Bead 9: W3C Timer and Cancel Tests
Write tests for: timer cancellation (test208), cross-machine timer isolation (test207), timer-based delayed events (test185).
**Estimated tests: 3**

### Bead 10: W3C System Variables and Event Fields Tests
Write tests for: triggeringEvent persistence (test318), triggeringEvent null before first event (test319), machineId available at start (test321), machineId immutable (test322), machine name available (test323, test324), system var immutability (test329, test346), event fields (test205, test330, test331), event name matching (test396).
**Estimated tests: 12**

### Bead 11: W3C Context / Data Model Tests
Write tests for: early binding (test279, test550), context assignment validation (test286, test287, test311, test487), invalid context in done routing (test298).
**Estimated tests: 6**
