# Machine Wiring Validation

Make `machine:validate` a check CI can gate on, and give it three checks that today only fail at runtime. Target release: **9.16.0** (current 9.15.2) — additive plus one deliberate breaking change (§8.1).

## 1. Motivation

`machine:validate` cannot fail a build: `handle()` returns `void`, so the command exits 0 even when a machine is unresolvable, has no definition, or throws. Two shipped artifacts are built on that false premise — the `->assertSuccessful()` recipe in `docs/testing/recipes.md`, an assertion that cannot fail, and the scheduled `machine:validate --all` with `emailOutputOnFailure` in `docs/laravel-integration/artisan-commands.md`, a hook that can never fire.

What the command checks today is config shape. It never compares a behavior against the context the machine declares, so three defects reach production:

1. **A behavior wired to a context it does not accept.** Injection passes the machine's context and PHP type-checks it when the behavior is invoked, so the failure is a `TypeError` on the first execution of that transition — possibly a rare branch, weeks after deploy. This is live risk: in the largest consumer, 13 behaviors shared between machines declare unions of concrete context classes, **referenced by bare FQCN from state config rather than from the inline behavior maps**, and referencing one from a machine outside its union passes every existing check.
2. **A `$requiredContext` key the context cannot supply** — verified only at runtime, per invocation.
3. **Two event classes deriving the same event type in one machine.** `EventBehavior::getType()` derives the type from the class name; the derived type keys both the transition table and the machine's event registry, so a second class deriving the same type silently replaces the first — and that registry is what reconstructs persisted events, so payload validation can come from the wrong class.

## 2. What the command must do

1. `machine:validate` returns a meaningful exit code: success only when at least one machine was validated and none produced findings; failure when any machine produced findings, was unresolvable, had no definition, or threw; a usage error when invoked with neither a machine argument nor `--all`.
2. A machine that throws during validation is reported and the sweep continues to the remaining machines. A run that could not complete is never reported the same way as a clean one.
3. `--all` reporting zero discovered machines is a failure, and the command reports how many machines it discovered. That count is informational: only zero fails, so it cannot serve as a regression signal on discovery. A project that needs one names its machines explicitly (§5).
4. Every check applies identically whether the command was invoked with `--all` or with named machines, and the same machine yields the same verdict either way.
5. For every behavior a machine references, the command reports a finding when the machine's declared context is not one the behavior accepts.
6. For every behavior a machine references, the command reports a finding when a `$requiredContext` key cannot be satisfied by the machine's declared context class.
7. The command reports a finding when two distinct event classes reachable from one machine derive the same event type.
8. Findings are grouped per machine and name the behavior, the expectation and the actual value, so a reader can act without opening the code.

## 3. Correctness of the three checks

Requirements 2.5–2.7 restate rules the engine already implements. This spec deliberately does not restate them: three rounds of review established that every prose re-derivation diverged from the engine, in both directions. Each check instead gets a stated correctness condition and the coverage it must achieve.

### 3.1 Coverage — which behaviors must be checked

The checks are only as good as the set of behaviors they run over, and a verdict-only requirement does not constrain that set: an implementation reading just the inline behavior maps would satisfy every verdict below and still miss §1.1's motivating case entirely.

**The command must check every behavior class a machine references, by whatever route it is referenced** — inline behavior maps, bare FQCN in state config, behavior tuples, lifecycle and timer keys, endpoints, schedules, and scenario states where scenarios are enabled. A test asserts the derived set against a fixture machine that references behaviors through each route, and that test fails if a route is dropped.

**The set stops at the delegation boundary.** A behavior reached through a child machine, a `job` state, or a `forward` endpoint runs under the *child's* context, not this machine's, so checking it here would produce a false failure on every parent. Those behaviors are checked when the child machine itself is validated. Classes reached by these routes that are not `InvokableBehavior` implementations — endpoint action classes, schedule resolvers, event classes — are likewise outside the behavior set; event classes feed §3.4 only.

### 3.2 Context compatibility (2.5) — a condition at the call boundary

The engine's parameter resolution does not fail on a context mismatch; it returns the machine's context and the `TypeError` is raised when the resolved arguments are passed to the behavior. The correctness condition is therefore stated at the **call** boundary, not the resolution boundary.

**Scope: the context argument only.** The engine's answer for other parameters depends on the call site within a macrostep — which event is current, whether a child output is present — so a relation quantified over (behavior, machine) is not well defined for them. The context argument is the exception: it is the machine's context at every call site, which is what makes this check decidable. Parameters whose injected value varies by call site are outside this check.

> The command reports a finding for a (behavior, machine) pair **if and only if** invoking that behavior under that machine's declared context would fail because the context argument is not acceptable to it.

**There is no engine verdict to call.** The one shared resolution point never signals a mismatch and reduces a union to its first member, so it is blind to several of the shapes below; any implementation must re-derive the rule from the parameter's declared type. Four rounds of review established that every such re-derivation drifts, so the defence is not a preferred mechanism but the test table: fixture behaviors with trivial bodies are invoked through the engine, the outcome is observed, and the command's verdict must match on every listed shape.

Shapes the tests must cover, each with its expected verdict recorded in the table: exact context type; a supertype; a union whose matching member is not first; a union whose first member is not a context type; a union with no matching member; a union including `null`; two context-typed parameters; a variadic parameter; a parameter that is untyped; one typed with a non-context class; one typed `State`, `EventCollection` or a concrete `EventBehavior` subclass; one intersection-typed; a parameter with a default value; a closure behavior; a behavior class with no `__invoke`; a machine with no typed context; and the same behavior against two machines with different contexts — §1.1's own case, which no single-machine shape exercises.

Two of these shapes fail before the call rather than at it (an unresolvable required parameter, and a class that cannot be reflected). The table records those verdicts explicitly rather than folding them into "type error", so an implementation cannot pass by treating every non-`TypeError` outcome as success.

### 3.3 `$requiredContext` (2.6) — a static declaration check, not agreement

The engine satisfies `$requiredContext` against the **live context instance** — presence in its data and the runtime type of the current value — so a key an earlier action populates satisfies the engine while being invisible statically. Agreement with the engine is therefore undecidable here and is explicitly **not** the condition.

The check is a decidable approximation over the declared context class. It **errs toward silence**: a key that might be satisfiable at runtime is not reported, because a false failure in a CI gate is worse than a missed one.

Erring toward silence must not make the check vacuous. A base `ContextManager` accepts arbitrary keys through `set()`, so "unsatisfiable under any runtime state" is true of nothing and a never-reporting implementation would pass. **The test table therefore carries positive verdicts**: at minimum, a key that a typed context class does not and cannot declare must be reported, and the table records which keys must be reported alongside those that must not. The spec does not prescribe how the declaration surface is read; the tests fix the verdicts, including both `$requiredContext` shapes and a dotted key against both a base and a typed context, which the engine resolves differently.

### 3.4 Event type collision (2.7)

The condition is decidable without the engine: two distinct classes reachable from one machine deriving the same type is a finding. "Reachable" is the set from §3.1 plus event classes named anywhere in the definition's inputs.

**The finding names the class that currently owns the type.** Ownership is the machine's event registry, filled last-writer-wins as the definition is traversed, so a consumer cannot determine it by reading config — and §8.3's remediation depends on knowing it. Where a colliding class is reachable but never reaches the registry, the finding says so rather than naming an owner.

Tests cover two classes deriving the same type from differently-shaped names, a class that overrides the derivation, the same class referenced repeatedly, collisions spanning two states and a state plus an endpoint, and the reported owner in each case.

## 4. What must not change

1. **No observable runtime behavior change.** Injection, machine boot, transition execution and what is persisted all behave exactly as before. The checks live in the command; no logic is extracted out of the per-transition path, which keeps the guarantee a fact rather than a claim needing evidence. The command builds definitions and never sends an event or writes state — but §3.1's coverage does instantiate every referenced behavior class, with whatever the container injects into its constructor, so "read-only" means it writes no machine state, not that it constructs nothing. That distinction matters because the shipped scheduler example runs `--all` in production.
2. No new exception is thrown outside the command. The event-type collision is a validator finding, not a definition-build throw, so a project that has lived with a collision upgrades without its machines failing to boot.
3. The command stays opt-in and gains no opt-out flag: a project that does not run it is unaffected, and a `--strict` escape would leave the default reporting a false green.

## 5. Machine discovery

The command recognises only classes that directly extend `Machine`, so a machine extending an intermediate base class is invisible to it. That gap is pre-existing, but §2.1 would turn it from silence into a failure on the **named** path: a valid machine would become unresolvable and exit non-zero.

**The decision: a named machine is resolved as a class first.** When the argument names a class that exists and extends `Machine`, it is validated whether or not discovery found it. Only when no such class exists does the command fall back to matching the argument against the discovered set, and only a genuine miss is a failure. This removes the false failure without widening discovery, and it is what makes §10's fixture home reachable by name.

`--all` keeps the pre-existing limitation, which §6 documents.

## 6. Documentation

| File | Change |
|------|--------|
| `laravel-integration/artisan-commands.md` | Document the three checks and the exit-code contract. Replace the *Example Output* and *Error Examples* blocks — they show output the command has never produced. Note that the scheduler example's `emailOutputOnFailure` hook becomes live. |
| `testing/recipes.md` | The `->assertSuccessful()` recipe asserts something that cannot fail today; update it and its prose once the assertion becomes meaningful. |
| `laravel-integration/overview.md` | Update the `machine:validate` row: wiring validation, non-zero exit on failure. |
| `building/defining-states.md` | It documents a bare `machine:validate` as scanning all machine classes; under §2.1 that invocation is a usage error. |
| `advanced/machine-delegation.md` | Its claim that the command "throws if any child final state is uncovered" is the one doc statement that already promises failure; verify it reads correctly against the new exit codes. |
| `getting-started/upgrading.md` | The repo's breaking-change guide, and §8.1 ships a subclass fatal. |
| `best-practices/event-design.md` | New section on how the event type is derived from the class name, that the derivation is overridable, and that two differently-named classes can derive the same type. Derive the rule from the code when writing it. |
| `best-practices/context-design.md` | Extend *Best Practice: Typed ContextManager*: a behavior's context type-hint and its `$requiredContext` are its contract with a machine, and both are now checked before runtime. |

Documentation states what a passing run does **not** guarantee, because two limitations feed the same exit code: `--all` sees only machines that directly extend `Machine` (§5), and the `$requiredContext` check errs toward silence by design (§3.3). A green exit means no finding was produced, not that the wiring is complete. New code blocks pass DocTest or carry one of its attributes; internal links use `https://eventmachine.dev/...`.

## 7. Agent skill

`skills/event-machine/SKILL.md`: add `machine:validate --all` to the workflow checklist, update the Artisan-commands row, and add gotchas for the event-type derivation and for the context type-hint being a behavior's compatibility contract. Verify each section number against the file before editing. Docs and skill ship in the same tag as the code — the skill reaches agents only when a tag fires the build, so a row left stale here stays stale for a full release cycle.

## 8. Backward compatibility

1. `handle()` changes from `void` to `int`, and the per-machine methods it delegates to change with it. A consumer subclassing the command hits a **load-time** fatal on a discovered console class, which takes out every `php artisan` invocation — including the ones a deploy runs before migrations. Release-note the names and the blast radius.
2. **The command can now fail.** Release notes must name three consequences: a CI pipeline already running it starts failing on machines that were silently invalid; consumer suites that copied the `assertSuccessful()` recipe have an assertion that has never been able to fail; a scheduled run with `emailOutputOnFailure` has a dead alert hook that becomes live. The instruction is to upgrade on a branch or staging and run the command **there** — running it before upgrading exercises the version that cannot fail and produces exactly the false green this work exists to remove.
3. **Clearing a collision finding changes an event type**, and the derived type is what is persisted and what transitions are keyed by. The collision finding names the owning class (§3.4), so the release note points at that output rather than asking the consumer to work it out; it must note that the derivation is overridable so the choice determines whether persisted data is affected at all, and cover rows already written under a retired type — including archived ones, which a fix written against the events table alone would skip, and in-flight queued payloads that carry the retired type string — and the rollback direction.
4. The release note is a deliverable of this work, and follows the repo's `spec/<version>-*-release-notes.md` convention. The spec file is version-prefixed before tagging.

## 9. Out of scope

- Widening `--all` discovery beyond classes that directly extend `Machine`. §5 removes the false failure on the named path; changing discovery itself is separate work.
- Comparing `$requiredContext` declared types against the context property's declared type.
- Any namespace, folder, or layering rule; any guidance on sharing behaviors across machines.
- An opt-out flag (§4.3).

## 10. Implementation order

1. Exit codes and the shared per-machine path first — without them nothing else can gate CI, and alone they already fix the two false-confidence artifacts in §1.
2. §3.1 coverage next: the set of checked behaviors is what every later check runs over.
3. The three checks, each behind its §3 correctness condition and its test table.
4. Reporting once at least one check produces findings.
5. Documentation, skill and release note alongside the code; nothing here may be released partially.

Two things are settled in step 1, not discovered later. The existing package tests assert the current always-successful behavior and must be updated. Intentionally-invalid fixtures need a home outside the directories discovery walks. Discovery recurses the project's PSR-4 *directories*, so an autoload mechanism alone does not exclude a file under one of them: the fixtures must live in a directory that is not a PSR-4 root at all, loaded by a classmap entry, and they stay reachable by name through §5's class-first resolution. The package's own machine stubs must also be run against §2.5–§2.7 before `--all` is asserted green, because a pre-existing wiring problem among them would turn the suite red for reasons unrelated to this change.
