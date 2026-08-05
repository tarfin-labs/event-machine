# Machine Families: Static Wiring Validation & Shared-Behavior Doctrine

Make it safe to share behavior implementations across sibling machines — machines that are variants of the same workflow — by (a) validating at build time that every behavior is wired to a compatible context, (b) rejecting event-type collisions that today shadow silently, (c) reporting an incompatible context precisely at runtime, and (d) documenting the folder and layering doctrine that governs shared code.

## 1. Motivation (context)

EventMachine has no first-class answer to "N machines that are variants of each other". The engine already supports sharing — parameter injection resolves a context parameter by `is_subclass_of(..., ContextManager::class)` and passes `$state->context`, so a behavior type-hinting an abstract base context works in every machine whose context extends it — but nothing verifies the wiring, and nothing documents the pattern. Consumers therefore fork.

An audit of the largest consumer (tarfin-core backend) measured the cost of forking. `IyiFinansCarSales` was forked from `CarSales`; a third channel (`TractorSales`) is now being added:

- 81 files in the fork, 73 of which have a same-named counterpart in the original.
- **55 of those 73 are identical** once namespace, comment wording and import order are normalised. Only 18 differ in behavior, and most of those 18 differ solely by a channel constant (sales-channel enum, frontend URL path, notification target).
- Config duplication is larger than behavior duplication: of the fork's 338 semantic config lines, 305 also appear verbatim in the original. The 92-line `verification` region (parallel Findeks ‖ TÜRMOB with child-machine delegation) is **byte-for-byte identical**; the 108-line `idle` region differs by one line.

Three engine facts make consolidation both possible and hazardous, and none of them is documented:

1. **Behavior classes are never persisted by FQCN.** Event types are derived from the class basename (`EventBehavior::getType()`), the context class comes from `behavior.context` in config, and `machine_events` stores `type`/`source` strings. Moving a behavior or event class to another namespace is therefore safe for live machines.
2. **Machine classes ARE persisted by FQCN** — `machine_current_states.machine_class`, `machine_children.parent_machine_class`, `machine_children.child_machine_class`. Moving a machine class requires a data migration. The asymmetry between (1) and (2) determines what a consolidation refactor may and may not move.
3. **Same-basename event classes silently shadow each other.** `StateDefinition::createTransitionDefinitions()` registers `$machine->behavior['events'][$eventClass::getType()] = $eventClass` and keys transitions by the same string. Two classes with the same basename reachable from one machine collapse to one entry, last registration wins, no warning — and since that map is what resolves an incoming event to its class, payload validation rules can silently come from the wrong class. This is precisely the hazard of a migration that introduces a shared event alongside the per-machine one it replaces.

Meanwhile `machine:validate` cannot gate CI: `MachineConfigValidatorCommand::handle()` is declared `void`, so the command exits 0 even when a machine fails validation, and `StateConfigValidator` performs structural checks only — it never reflects a behavior's signature against the machine's declared context.

## 2. Scope

Package-only (event-machine repo): validation, one runtime exception, tests, documentation, and agent skill updates. No consumer migration is in scope; the doctrine ships as documentation that consumers apply themselves.

The three code items are ordered by value: §3 (static validation) is the item that makes a shared-behavior refactor safe; §4 (collision) closes a silent-corruption hole; §5 (runtime exception) is a safety net for wiring that §3 cannot reach.

## 3. `machine:validate`: behavior wiring validation

`machine:validate` becomes a CI-gateable check that every behavior referenced by a machine is compatible with the context that machine declares.

### 3.1 Exit codes

1. `MachineConfigValidatorCommand::handle()` changes its return type from `void` to `int` and returns `Command::SUCCESS` (0) when every validated machine passes, `Command::FAILURE` (1) when any machine fails or cannot be resolved.
2. `validateMachine()` returns a boolean (or accumulates into a failure counter) rather than swallowing the outcome; the existing `catch (Throwable)` still prints its message but now also marks the run failed.
3. `machine:validate` with no arguments and no `--all` keeps its current "please provide a machine class name" message and returns `Command::INVALID` (2).
4. The command's existing output lines are unchanged in wording; only the exit code and the new findings from §3.3–§3.5 are added.

### 3.2 Behavior collection

1. A new `MachineDefinition::referencedBehaviors(): array<string, list<string>>` returns, keyed by `BehaviorType` value, every behavior class referenced by the machine. Class strings only — closures and inline keys that resolve to closures are skipped (they are checked at runtime, not statically).
2. Collection walks two sources, because behaviors referenced by FQCN directly in state config are never registered into `MachineDefinition::$behavior`:
   a. the `$behavior[BehaviorType::*]` maps (values that are strings and satisfy `class_exists()`);
   b. the resolved `$config` tree, recursively, collecting every string value that satisfies `class_exists()` and `is_subclass_of(..., InvokableBehavior::class)`, plus element `[0]` of any behavior tuple parsed by `BehaviorTupleParser`.
3. Event classes are collected separately (they are `EventBehavior` subclasses and appear as config **keys**, not values) and feed §4 only — they have no context parameter to check.
4. The returned lists are deduplicated and sorted, so output is deterministic across runs.

### 3.3 Context compatibility check

1. For each collected behavior class, reflect `__invoke` and find its context parameter: the first parameter whose declared type — or, for a union, any member of that union — satisfies `is_a($type, ContextManager::class, true)`. A behavior with no such parameter is skipped.
2. Let `$contextClass` be the machine's declared context (`$definition->behavior[BehaviorType::Context->value]`, defaulting to `ContextManager::class` when the machine declares none).
3. The behavior passes when `is_a($contextClass, $memberType, true)` holds for **at least one** context-typed member of the parameter's type. It fails otherwise.
4. A failure is reported as: `{BehaviorClass}::__invoke() expects {ExpectedTypes} but machine {MachineClass} declares context {ActualContextClass}.` — where `{ExpectedTypes}` lists the context-typed union members separated by `|`, in declaration order.
5. The check is performed only by `machine:validate`. It is NOT performed at `MachineDefinition::define()` time: reflecting every behavior on every machine boot is per-transition-path cost paid in production for a defect class that is deterministic and therefore belongs in CI.

### 3.4 `$requiredContext` key check

1. For each collected behavior class with a non-empty `static $requiredContext`, verify each key exists as a public property on the machine's declared context class (`property_exists($contextClass, $key)`).
2. When the machine's declared context is the base `ContextManager` (untyped, data-array backed), the check is skipped for that machine — keys live in the data array, not as properties, and `property_exists` would produce false failures.
3. A failure is reported as: `{BehaviorClass}::$requiredContext['{key}'] is not a property of {ContextClass} (machine {MachineClass}).`
4. The declared type in `$requiredContext` is NOT checked against the property's declared type in this spec (see §10).

### 3.5 Reporting

1. Findings are printed grouped by machine, one line per finding, after the existing per-machine line. The existing `✓ Machine '{class}' configuration is valid.` line is printed only when the machine produced no findings.
2. A machine with findings prints `✗ Machine '{class}' has {n} wiring problem(s):` followed by the finding lines.
3. `--all` validates every discovered machine and returns failure if any machine has findings; it does not stop at the first failure.

### 3.6 Example

```
$ php artisan machine:validate --all
✓ Machine 'App\Machines\CarSales\CarSalesMachine' configuration is valid.
✗ Machine 'App\Machines\TractorSales\TractorSalesMachine' has 2 wiring problem(s):
  App\Machines\Support\VehicleSales\Actions\StoreOrderAction::__invoke() expects
    App\Machines\CarSales\CarSalesContext but machine
    App\Machines\TractorSales\TractorSalesMachine declares context
    App\Machines\TractorSales\TractorSalesContext.
  App\Machines\Support\VehicleSales\Actions\StoreOrderAction::$requiredContext['carSalesVehicle']
    is not a property of App\Machines\TractorSales\TractorSalesContext
    (machine App\Machines\TractorSales\TractorSalesMachine).
$ echo $?
1
```

## 4. Event type collision detection

### 4.1 Requirements

1. During machine definition build, when an `EventBehavior` subclass is registered into `$machine->behavior[BehaviorType::Event->value]` keyed by `$eventClass::getType()`, and that key is already occupied by a **different** class, throw `EventTypeCollisionException`.
2. Registering the same class twice under the same type is not a collision (idempotent registration must stay legal).
3. The exception is built by a named static factory and its message names the type string and both classes: `Event type '{TYPE}' is produced by both {ClassA} and {ClassB}. Event types are derived from the class basename, so two event classes with the same basename cannot be used in one machine — rename one or alias it to a distinct basename.`
4. The check covers every registration site that writes that map (`StateDefinition::createTransitionDefinitions()` and the sibling site that resolves event names for other config keys), so a collision across two different states is caught as well as one inside a single `on` block.
5. The exception is thrown at definition build time, not at transition time, so it surfaces identically in `machine:validate`, in tests, and on first boot.

### 4.2 Backward compatibility evidence

A collision is difficult to introduce accidentally via `use` statements, because PHP rejects two imports with the same basename in one file; it requires an alias or a fully-qualified reference. Empirically:

- Zero collisions across every machine in the package's own stubs (`tests/Stubs`) and `src`.
- Zero collisions across all 15 machine domains in the largest consumer.
- Zero aliased event imports in either codebase.

The check is therefore expected to be a no-op on existing code and to fire only on the migration pattern it exists to catch.

## 5. Precise incompatible-context reporting

### 5.1 Problem statement, honestly scoped

When a behavior is wired to a machine whose context it does not accept, PHP already throws a `TypeError` naming the behavior, the expected type and the given type. What that error does **not** say is which machine, which state, or which transition — and its stack frame points into `InvokableBehavior`, which is a dead end for a consumer. This item improves the message; it does not fix a wrong result. It is the lowest-value of the three code items and is included because §3 cannot reach dynamically composed configs.

A related correction to the framing that motivated this item: `InvokableBehavior` resolving a union via `getTypes()[0]` is **not** a live bug for the common `ContextA|ContextB|ContextC` usage. The first member is used only to choose the injection *category* (context / event / state / history); the injected value is the real object, and PHP validates it against the whole union. First-member-wins is wrong only when union members span different categories, which no sane signature does. The change below is robustness, not a defect fix.

### 5.2 Requirements

1. Extract the union/named type handling in `InvokableBehavior::injectInvokableBehaviorParameters()` into a helper that iterates union members **in declaration order** and returns the first member for which an injection category resolves, instead of unconditionally taking `getTypes()[0]`.
2. When the resolved category is "context", inject `$state->context` only if `$state->context` is an instance of at least one context-typed member of the parameter's type. Otherwise throw `IncompatibleContextException`.
3. `IncompatibleContextException` is built by a named static factory and its message names the behavior class, the expected context type(s), the actual context class, the machine id (`$state->context->machineId()`) and the current state value.
4. Behavior for every currently-working signature is unchanged: same injected value, same order, no new exception on any input that works today.
5. `mixed`-typed parameters are explicitly not changed (see §10).

## 6. Documentation: `docs/best-practices/machine-families.md`

A new best-practices page covering horizontal composition — N sibling machines that are variants of one workflow. This is a genuine gap: `machine-decomposition.md` covers vertical splitting (parent/child) and `machine-system-design.md` covers how machines communicate; nothing covers machines that are variants of each other.

### 6.1 Required content

1. **Core principle**: a behavior can be shared by every machine whose declared context satisfies the behavior's context type-hint. The type-hint is the sharing contract; nothing else is needed and nothing else should be invented.
2. **The ten rules**, each with a one-line rationale and, where applicable, a code example:
   - **R1** A behavior lives in the layer of the narrowest context it type-hints (app-wide → kernel, family → family library, single machine → that machine).
   - **R2** "Shared" is not a domain name. It is legitimate as a *layer* name, never as a sibling of domains.
   - **R3** The family folder is named after its abstract context. If you cannot name the abstract context, there is no family — only two machines that currently resemble each other.
   - **R4** Live machine classes are not moved; library classes move freely. (See R10.)
   - **R5** The family folder mirrors a machine's own sub-structure (`Actions/`, `Guards/`, `Calculators/`, `Events/`, `Outputs/`, `Notifications/`) plus `States/` for config fragments.
   - **R6** Share when it changes together, not when it looks the same. Test: "if one channel's owner changes this rule tomorrow, do the others change too?"
   - **R7** The first `if ($channel)` inside a shared behavior demotes it back to per-machine copies. Never add the condition.
   - **R8** Tests mirror the layer: a shared behavior gets one test run against every family context via a dataset.
   - **R9** Nothing that is not a machine sits directly under the machines root.
   - **R10** Move cost is determined by what is persisted — behaviors and events are free, machine classes require a data migration.
3. **Channel variance without branching**: abstract methods on the family context (`abstract public function salesChannel(): SalesChannelType;`) as the primary mechanism, and the existing named-config-params tuple syntax (`[ActionClass::class, 'param' => value]`, parsed by `BehaviorTupleParser`) as the alternative for values that belong to the wiring rather than to the context.
4. **Config fragments are plain PHP**: static methods returning array chunks, resolved before `MachineDefinition::define()` sees them, with named arguments for the parts that vary. An explicit "why the package does not offer a config-partial or machine-inheritance mechanism" subsection: a DSL would be strictly less expressive than PHP functions, would introduce a second resolution phase that `machine:xstate`, `PathEnumerator`, `machine:paths`, `machine:coverage` and `StateConfigValidator` would all have to learn, and array-merge inheritance has unwinnable semantics (does `on` merge or replace? does `entry` append or override?).
5. **Three engine facts** as a dedicated "what you must know before moving files" section: behavior FQCNs are not persisted; machine FQCNs are; event types come from the basename.
6. **The worked example**: the CarSales / IyiFinansCarSales / TractorSales case with the measurements from §1, the before/after tree, and the migration order (abstract context → identical behaviors → config fragments → third machine built on the core).
7. **Rejected alternatives**, each with its reason: a `-Shared` suffix on a sibling folder (does not scale past one family; the prefix becomes a lie when the family widens); nesting live machines under the family folder (persisted `machine_class`); a config-partial DSL (item 4); machine class inheritance with config merge.
8. **The validator's role**: `machine:validate` from §3 enforces R1 indirectly and exactly — a misplaced behavior is only a defect when it is *used* incompatibly, which is what the type check catches. The page states explicitly that no namespace-based lint rule exists or is wanted, because folder placement is a project convention while type compatibility is a correctness property.

### 6.2 Documentation wiring

1. `docs/best-practices/index.md`: add a Quick Reference row — `[Machine Families](./machine-families) | Sharing behavior across sibling machines that are variants of one workflow`.
2. `docs/best-practices/machine-decomposition.md` and `machine-system-design.md`: add a cross-reference in their `## Related` sections.
3. All code blocks pass DocTest or carry one of the DocTest attributes (`ignore`, `no_run`, `bootstrap`).
4. Internal links use `https://eventmachine.dev/...`.

## 7. Agent skill updates

1. `skills/event-machine/SKILL.md` §8 (Documentation Navigation): add a row for `best-practices/machine-families`.
2. SKILL.md §7 (gotchas): add three entries — "event types come from the class basename, so two same-basename event classes in one machine now throw `EventTypeCollisionException`"; "behavior/event class FQCNs are not persisted but machine class FQCNs are (`machine_current_states.machine_class`, `machine_children.*_machine_class`) — moving a machine class needs a data migration"; "a non-nullable context property makes every previously persisted machine unrestorable".
3. SKILL.md §9 (workflow checklists): add a "building a second machine that is a variant of an existing one" checklist — declare the abstract context first, type-hint it from shared behaviors, run `machine:validate --all`, keep divergent business rules per-machine.
4. SKILL.md §1 or §3: state the sharing contract in one line — a behavior is shared by type-hinting a context supertype; a union of context classes works but does not scale, because every new sibling edits every shared behavior.

## 8. Package tests

1. `referencedBehaviors()`: behaviors reachable only via the `behavior:` maps; behaviors reachable only via FQCN in state config; behaviors inside a `BehaviorTupleParser` tuple; behaviors under `entry`, `exit`, `listen.{entry,exit,transition}`, `after`/`every`, and `@always`/`@done`/`@fail`/`@timeout` branches; closures and inline keys excluded; deduplicated and deterministically ordered output.
2. Context compatibility: passing machine (exact context); passing machine (behavior type-hints an abstract ancestor of the machine's context); passing machine (union where a non-first member matches); failing machine (unrelated context) produces exactly the §3.3.4 message; behavior with no context parameter skipped; machine declaring no context defaults to `ContextManager` and accepts a `ContextManager`-typed behavior while rejecting a subclass-typed one.
3. `$requiredContext`: key present as a property passes; key absent produces exactly the §3.4.3 message; base-`ContextManager` machine skips the check entirely.
4. Command behavior: `machine:validate <machine>` exits 0 on a clean machine and 1 on one with findings; `--all` exits 1 when any machine has findings and validates all machines rather than stopping at the first; no arguments and no `--all` exits 2; an unresolvable machine class exits 1.
5. `EventTypeCollisionException`: two same-basename event classes referenced from one `on` block throw with both class names in the message; the same collision across two different states throws; registering the same class twice does not throw; the exception surfaces from `machine:validate` as a failing machine rather than an uncaught error.
6. `IncompatibleContextException`: a behavior type-hinting a context the machine does not declare throws it instead of a bare `TypeError`, and the message contains the behavior class, expected type(s), actual context class, machine id and state value; every existing injection case (context, event, state, history, config params, defaults, `@always` `triggeringEvent` substitution) produces the identical value it produces today, including union parameters whose matching member is not first.
7. A shared-behavior regression fixture: one abstract context, two machines with distinct concrete contexts, one behavior type-hinting the abstract context, asserting it runs correctly in both machines and that `machine:validate --all` passes.
8. `composer quality` passes (pint, rector, phpstan as configured, 100% type coverage, unit tests).
9. DocTest passes with 0 failures.

## 9. Backward compatibility

1. `MachineConfigValidatorCommand::handle()` return type changes `void` → `int`. A consumer subclassing this command and overriding `handle(): void` hits a covariance fatal; release-note it. The behavioral change — the command can now fail — is the point of the item and must be called out, because a CI pipeline that already runs `machine:validate --all` will start failing on machines that were silently invalid.
2. `EventTypeCollisionException` is a new throw at definition build time. Evidence in §4.2 shows zero collisions in the package's stubs and in the largest consumer, and PHP's import rules make accidental introduction unlikely. Release-note it as a strictness change with the remedy (rename or alias to a distinct basename).
3. `IncompatibleContextException` replaces a `TypeError` on a path that was already fatal. Code that caught `TypeError` around a machine transition would stop catching it; the new exception must therefore extend a base the package already uses for behavior faults, and the release note must name it.
4. `MachineDefinition::referencedBehaviors()` is additive.
5. No change to what is persisted, to event type derivation, to injection results, or to any machine's runtime behavior.
6. New method name `referencedBehaviors` can collide with a method a consumer defined on a `MachineDefinition` subclass; release-note the name.

## 10. Out of scope

- Any namespace or folder lint rule in the package. Folder placement is a project convention (documented in §6); type compatibility is the correctness property (validated in §3). Encoding one project's directory layout into the package is explicitly rejected.
- A config-partial, fragment-include, or machine-inheritance mechanism (§6.1.4 documents why).
- Changing how `mixed`-typed behavior parameters resolve. A `mixed` parameter currently injects `null` unless a named config param matches by name; mapping it to the event would break the config-param path, which is matched later in the resolution order. The behavior is documented as a gotcha instead.
- Type checking `$requiredContext` declared types against the context property's declared type (§3.4.4) — a separate item once the key check has bedded in.
- Consumer-side migration of any machine, folder, or namespace.
- A generated report of behaviors duplicated across machines (a one-off script, not a package feature).
- Caching `referencedBehaviors()` — `machine:validate` is a dev/CI command and pays reflection once per run.

## 11. Implementation order

1. §3.1 (exit codes) first and alone — without it nothing else in §3 can gate CI, and it is independently releasable.
2. §3.2 (`referencedBehaviors()`), then §3.3 and §3.4 in parallel (both consume it), then §3.5 reporting.
3. §4 (collision) is independent of §3 and can land in parallel; it surfaces through §3.5 for free once both are in.
4. §5 last: its exception message reuses the expected/actual formatting introduced in §3.3.4, and §3 removes most of the cases that would reach it.
5. §6 (docs) and §7 (skill) accompany the code; per repo workflow rules they ship in the same tag — a skill commit after the tag does not reach agents until the next tag.
6. §8 tests accompany each item as it lands; the quality gate and DocTest run last.
