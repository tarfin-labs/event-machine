# LCA-Aware Recursive Exit/Entry

**Status:** Upcoming
**Affects:** `MachineDefinition::transition()`, `MachineDefinition::enterState()`, `StateDefinition::runExitActions()`, `StateDefinition::runEntryActions()`
**SCXML Reference:** W3C SCXML Section 3.13 (Selecting and Executing Transitions), Appendix D (Algorithm for SCXML Interpretation)
**Skipped Tests:** `tests/Features/ActionOrderingTest.php` tests #2 (deep hierarchy) and #3 (sibling transition)

---

## Problem Statement

### Current Behavior

EventMachine runs exit actions **only on the transition source state** during a transition. It does not walk up the state hierarchy to exit ancestor states up to the Least Common Ancestor (LCA), nor does it walk down from the LCA to enter ancestor states of the target.

In `MachineDefinition::transition()` (line 2964), the exit protocol is:

```php
// Execute exit actions for the current state definition
$transitionBranch->transitionDefinition->source->runExitActions($state);
```

This calls `runExitActions()` on a single `StateDefinition` — the `source` of the `TransitionDefinition`. The `source` is set to the state where the `on` handler is defined, which for hierarchical transitions found via `findTransitionDefinition()` bubble-up may be an ancestor compound state rather than the current atomic state.

Similarly, `enterState()` (line 1199-1202) runs entry actions on the target and its resolved initial child, but does **not** enter intermediate ancestors between the LCA and the target:

```php
$target->runEntryActions($state, $eventBehavior);
if ($resolvedTarget !== $target) {
    $resolvedTarget->runEntryActions($state, $eventBehavior);
}
```

This handles exactly two levels (target + initial child) but not arbitrary depth.

### Correct SCXML Behavior (W3C Section 3.13)

The SCXML specification mandates that during a microstep:

1. **Exit set**: All active states that are descendants of the LCA (from innermost to outermost) must be exited
2. **Transition actions**: The transition's actions execute after all exits and before all entries
3. **Entry set**: All states from the LCA down to the target (from outermost to innermost) must be entered

The ordering is: **exit(inner-to-outer) -> transition actions -> entry(outer-to-inner)**.

### Which Transitions Are Affected

| Transition Type | Example | Current Behavior | Correct Behavior |
|---|---|---|---|
| **Cross-branch (deep)** | `A.A1.A1a -> B` | Exits only the `source` (A, where `on: GO` is defined) | Exit A1a, A1, A (inner-to-outer), then enter B |
| **Cross-branch (cousin)** | `A.A1 -> B.B1` | Exits only the `source` state | Exit A1, A (inner-to-outer), then enter B, B1 (outer-to-inner) |
| **Sibling** | `P.child_a -> P.child_b` | Exits `source` and enters target. If transition is on child_a, works. If transition is on P, exits/enters P incorrectly | Exit child_a only, enter child_b only. P (the LCA) is neither exited nor entered |
| **Child to ancestor** | `A.A1 -> A` | May re-enter A incorrectly | Exit A1 only. A is the target and the LCA, so it is exited and re-entered (external transition) |
| **Ancestor to descendant** | `A -> A.A1.A1a` | Partial entry | Exit A (self-transition semantics), enter A, A1, A1a |

### Which Transitions Are NOT Affected

| Transition Type | Why Unaffected |
|---|---|
| **Same-level flat** | `A -> B` at root level: LCA is root, current code exits A and enters B correctly |
| **Targetless** | No exit/entry at all (SCXML: targetless transitions don't change state) |
| **Self-transition (same level)** | `A -> A`: LCA is A's parent, exit A, enter A — already works at flat level |
| **Internal transitions** | `type: internal` makes source the LCA, so no exit/entry of source (not yet supported in EventMachine) |

---

## SCXML Algorithm Reference

### LCA Computation

The Least Common Ancestor of two states S1 and S2 is the lowest (deepest) state in the hierarchy that is a proper ancestor of both S1 and S2.

From SCXML Appendix D, `getTransitionDomain(t)`:
- For **external transitions** (the default): LCA = the smallest compound state that is a proper ancestor of both the source and the target
- For **internal transitions** (not yet supported): LCA = the source state itself (if source is compound and target is a descendant)

Algorithm to compute LCA:
```
function computeLCA(source: StateDefinition, target: StateDefinition): StateDefinition
    sourceAncestors = getProperAncestors(source)  // [parent, grandparent, ..., root]
    for each ancestor in sourceAncestors:
        if isProperAncestor(ancestor, target):
            return ancestor
    return root  // fallback (should not happen for valid transitions)
```

Where `getProperAncestors(state)` returns the chain `[state.parent, state.parent.parent, ..., root]` and `isProperAncestor(a, b)` checks if `a` is a proper ancestor of `b` (i.e., `b` is a descendant of `a` and `a != b`).

### Exit Set Computation

The exit set contains all active states that will be exited. Per SCXML:

```
function computeExitSet(activeState: StateDefinition, lca: StateDefinition): array<StateDefinition>
    // Walk from active atomic state up to (but NOT including) the LCA
    exits = []
    current = activeState
    while current != null AND current != lca:
        exits[] = current
        current = current.parent
    return exits  // inner-to-outer order
```

Exit actions are run in **document order** (innermost first), which matches the bottom-up walk since the active state is always the most deeply nested.

### Entry Set Computation

The entry set contains all states from the LCA down to the target that need to be entered:

```
function computeEntrySet(target: StateDefinition, lca: StateDefinition): array<StateDefinition>
    // Walk from target up to (but NOT including) the LCA, then reverse
    entries = []
    current = target
    while current != null AND current != lca:
        entries[] = current
        current = current.parent
    return array_reverse(entries)  // outer-to-inner order
```

Entry actions are run **outer-to-inner** (ancestors first, then descendants), so we reverse the walk.

After entering the bottom-most state in the entry set, if it is a compound state, we continue entering its initial child recursively (this is what `enterState()` already does with `findInitialStateDefinition()`).

### Special Cases

**Self-transition (A -> A):**
- LCA = A's parent
- Exit set = [A]
- Entry set = [A]
- Effect: A is exited and re-entered

**Transition from descendant to ancestor (A.A1 -> A):**
- LCA = A's parent (because LCA must be a *proper* ancestor of both)
- Exit set = [A1, A]
- Entry set = [A]
- Effect: A1 exits, A exits, then A re-enters (and its initial child enters)

**Transition from ancestor to descendant (A -> A.A1.A1a):**
- LCA = A's parent
- Exit set = [A] (or [current_active_descendant, ..., A] if A is compound)
- Entry set = [A, A1, A1a]
- Effect: Full exit and re-entry through the hierarchy

---

## Current Code Analysis

### Where Exit Actions Are Called

1. **`MachineDefinition::transition()` line 2964:**
   ```php
   $transitionBranch->transitionDefinition->source->runExitActions($state);
   ```
   This is the primary exit call for non-parallel transitions. It exits only the `TransitionDefinition->source` state, which is wherever the `on` handler was found (could be an ancestor if `findTransitionDefinition` bubbled up).

2. **`MachineDefinition::transitionParallelState()` line 2739:**
   ```php
   $sourceState->runExitActions($state);
   ```
   Same single-level exit pattern for parallel region transitions.

3. **`MachineDefinition::exitParallelStateAndTransitionToTarget()` lines 2587-2608:**
   Exits all active atomic states in parallel regions, then the parallel state itself. This is closer to correct hierarchical exit but is specific to parallel escape transitions.

### Where Entry Actions Are Called

1. **`MachineDefinition::enterState()` lines 1199-1202:**
   ```php
   $target->runEntryActions($state, $eventBehavior);
   if ($resolvedTarget !== $target) {
       $resolvedTarget->runEntryActions($state, $eventBehavior);
   }
   ```
   Handles exactly two levels: the target and its initial child. Does not enter intermediate ancestors between LCA and target.

2. **`MachineDefinition::enterParallelState()` line 566:**
   Enters the parallel state itself, then each region's initial state.

3. **`MachineDefinition::enterStateInParallelRegion()` line 1372:**
   Enters a single region initial state (parallel context).

### Key Issue: `findTransitionDefinition` Bubble-Up

`findTransitionDefinition()` (line 955) walks up the hierarchy to find a matching transition handler. When it finds one on an ancestor, `TransitionDefinition->source` is set to that ancestor. The current exit code then exits **only that ancestor**, skipping all intermediate states between the active atomic state and the ancestor.

Example with `A.A1.A1a -> B` where the `GO` handler is on state `A`:
- `findTransitionDefinition` walks: A1a (no handler) -> A1 (no handler) -> A (has `on: GO`)
- `TransitionDefinition->source` = A
- Current code exits A
- **Missing**: exit A1a, exit A1 (before A)

### Critical Insight: Active State vs. Source State

The `source` in `TransitionDefinition` is NOT the currently active state — it is the state where the transition handler is defined. The currently active state is `$state->currentStateDefinition`. The exit set must start from `$state->currentStateDefinition` (the active atomic state) and walk up to the LCA, not just exit `source`.

---

## Implementation Plan

### Step 1: Add LCA Computation Method to MachineDefinition

Add a method to compute the Least Common Ancestor of two `StateDefinition` objects:

```php
/**
 * Compute the Least Common Ancestor (LCA) of two state definitions.
 *
 * The LCA is the deepest compound state that is a proper ancestor of both
 * source and target. Per SCXML, for external transitions, the LCA must be
 * a proper ancestor of both (not equal to either).
 *
 * @param StateDefinition $state1 First state (typically the active atomic state).
 * @param StateDefinition $state2 Second state (typically the transition target).
 *
 * @return StateDefinition The LCA state definition.
 */
protected function computeLCA(StateDefinition $state1, StateDefinition $state2): StateDefinition
{
    // Collect all proper ancestors of state1 (parent chain up to root)
    $ancestors1 = [];
    $current = $state1->parent;
    while ($current !== null) {
        $ancestors1[] = $current;
        $current = $current->parent;
    }

    // Walk up state2's parent chain, find first match in state1's ancestors
    $current = $state2->parent;
    while ($current !== null) {
        if (in_array($current, $ancestors1, true)) {
            return $current;
        }
        $current = $current->parent;
    }

    // Fallback to root (should not happen for valid transitions)
    return $this->root;
}
```

**Why `$state->parent` not `$state` itself:** The LCA must be a *proper* ancestor of both states for external transitions. Starting from `parent` ensures the state itself is not considered as its own ancestor.

**Performance note:** Ancestor chains are small (typically 2-5 levels deep). The nested loop is O(d1 * d2) where d1, d2 are depths. This is negligible.

### Step 2: Add Exit Set Computation

```php
/**
 * Compute the set of states to exit during a transition.
 *
 * Returns states from innermost (active atomic state) to outermost
 * (child of LCA), in the order exit actions should run.
 *
 * @param StateDefinition $activeState The currently active atomic state.
 * @param StateDefinition $lca The Least Common Ancestor (not exited).
 *
 * @return array<StateDefinition> States to exit, inner-to-outer.
 */
protected function computeExitSet(StateDefinition $activeState, StateDefinition $lca): array
{
    $exits = [];
    $current = $activeState;

    while ($current !== null && $current !== $lca) {
        $exits[] = $current;
        $current = $current->parent;
    }

    return $exits;
}
```

### Step 3: Add Entry Set Computation

```php
/**
 * Compute the set of states to enter during a transition.
 *
 * Returns states from outermost (child of LCA) to innermost (target),
 * in the order entry actions should run.
 *
 * @param StateDefinition $target The transition target (before initial resolution).
 * @param StateDefinition $lca The Least Common Ancestor (not entered).
 *
 * @return array<StateDefinition> States to enter, outer-to-inner.
 */
protected function computeEntrySet(StateDefinition $target, StateDefinition $lca): array
{
    $entries = [];
    $current = $target;

    while ($current !== null && $current !== $lca) {
        $entries[] = $current;
        $current = $current->parent;
    }

    return array_reverse($entries);
}
```

### Step 4: Modify `transition()` Exit Protocol

Replace the single-state exit at line 2958-2974 with hierarchical exit:

**Before (current):**
```php
// Exit protocol — only for targeted transitions (SCXML: targetless = no exit/entry)
if ($targetStateDefinition instanceof StateDefinition) {
    // Run exit listeners before state exit actions
    $this->runExitListeners($state);

    // Execute exit actions for the current state definition
    $transitionBranch->transitionDefinition->source->runExitActions($state);

    // Cancel active children when leaving a state with machine delegation
    $this->cleanupActiveChildren($state, $transitionBranch->transitionDefinition->source);

    // Record state exit event
    $state->setInternalEventBehavior(
        type: InternalEvent::STATE_EXIT,
        placeholder: $state->currentStateDefinition->route,
    );
}
```

**After (proposed):**
```php
// Exit protocol — only for targeted transitions (SCXML: targetless = no exit/entry)
if ($targetStateDefinition instanceof StateDefinition) {
    // Compute LCA between the active atomic state and the raw target
    // (before initial-child resolution). Use raw target because the LCA
    // must consider the declared target, not its resolved descendant.
    $rawTarget = $transitionBranch->target;
    $activeState = $currentStateDefinition; // the active atomic state
    $lca = $this->computeLCA($activeState, $rawTarget);

    // Compute exit set: active state up to (excluding) LCA
    $exitSet = $this->computeExitSet($activeState, $lca);

    // Run exit listeners once before the exit cascade
    $this->runExitListeners($state);

    // Run exit actions inner-to-outer
    foreach ($exitSet as $stateToExit) {
        $stateToExit->runExitActions($state);
    }

    // Cancel active children for all exited states that have machine delegation
    foreach ($exitSet as $stateToExit) {
        $this->cleanupActiveChildren($state, $stateToExit);
    }

    // Record state exit event (the overall exit from the source side)
    $state->setInternalEventBehavior(
        type: InternalEvent::STATE_EXIT,
        placeholder: $state->currentStateDefinition->route,
    );
}
```

### Step 5: Modify `enterState()` to Handle Hierarchical Entry

Replace the current two-level entry at lines 1198-1202 with hierarchical entry:

**Before (current):**
```php
// Run entry actions on target (and initial child if compound)
$target->runEntryActions($state, $eventBehavior);
if ($resolvedTarget !== $target) {
    $resolvedTarget->runEntryActions($state, $eventBehavior);
}
```

**After (proposed):**

The `enterState()` method needs to accept the LCA as a parameter (or compute it internally). Since `enterState()` is called from multiple places (transition, getInitialState, fire-and-forget, etc.), we need to handle the case where no LCA is provided (initial entry — enter everything from root down).

```php
protected function enterState(
    State $state,
    StateDefinition $target,
    ?EventBehavior $eventBehavior,
    bool $fireTransitionListeners = false,
    bool $processPostEntry = true,
    ?StateDefinition $lca = null,  // NEW parameter
): void {
    // Resolve to initial state if the target is a compound state
    $resolvedTarget = $target->findInitialStateDefinition() ?? $target;

    // Record state enter event
    $state->setInternalEventBehavior(
        type: InternalEvent::STATE_ENTER,
        placeholder: $resolvedTarget->route,
    );

    // Compute entry set: states from LCA down to target (outer-to-inner)
    if ($lca !== null) {
        $entrySet = $this->computeEntrySet($target, $lca);
    } else {
        // No LCA (initial entry): just enter the target itself
        $entrySet = [$target];
    }

    // Run entry actions outer-to-inner
    foreach ($entrySet as $stateToEnter) {
        $stateToEnter->runEntryActions($state, $eventBehavior);
    }

    // If resolved target differs from the deepest entry set member,
    // enter the initial child too (compound -> atomic resolution)
    $deepestEntry = end($entrySet) ?: $target;
    if ($resolvedTarget !== $deepestEntry && $resolvedTarget !== $target) {
        $resolvedTarget->runEntryActions($state, $eventBehavior);
    }

    // ... rest of enterState() unchanged (listeners, postEntry, delegation, onDone)
}
```

### Step 6: Update `transition()` to Pass LCA to `enterState()`

At line 3032, pass the LCA:

```php
if ($targetStateDefinition !== null) {
    $this->enterState(
        $newState,
        $targetStateDefinition,
        $eventBehavior,
        fireTransitionListeners: true,
        processPostEntry: false,
        lca: $lca ?? null,  // pass the computed LCA
    );
}
```

For initial entry (from `getInitialState()`), `lca` is null, so the current behavior is preserved.

### Step 7: Update Parallel State Exit/Entry

The `transitionParallelState()` method at line 2739 also uses single-state exit:

```php
$sourceState->runExitActions($state);
```

For intra-region transitions in parallel states, the LCA is the region's compound state (or deeper). The same LCA computation applies. The `exitParallelStateAndTransitionToTarget()` method already does a more thorough exit (walks all active states), but may also need refinement for deep hierarchies within regions.

**Parallel region transitions** need the same treatment: compute LCA between the active atomic state in the region and the target, then exit/enter the hierarchy within that region.

### Step 8: Handle Edge Cases

#### @always Chains Crossing Hierarchy

When an `@always` transition fires after entering a state, the transition source is the state that was just entered. The LCA computation works naturally: if state B has `@always -> C`, the active state is B, the target is C, and the LCA is computed between B and C.

No special handling needed — `@always` transitions re-enter `transition()` recursively, and the LCA computation applies to each hop independently.

#### Compound State `@done` Transitions

When a final state is reached inside a compound state, the `@done` transition fires on the compound state. The LCA is between the compound state and the `@done` target. The compound state's exit actions should already be part of the exit set.

The existing `processCompoundOnDone()` method triggers a new transition. This transition will naturally use the LCA algorithm.

#### Machine Delegation States

States with `machine` invoke definitions need their active children cleaned up when exited. The exit set computation ensures `cleanupActiveChildren` is called for each exited state, not just the transition source.

#### `getInitialState()` Entry Path

When a machine initializes, it enters from the root down to the initial atomic state. Currently `getInitialState()` calls `enterState()` with the initial state definition. Since there is no LCA (this is initial entry, not a transition), `lca` should be null. The `enterState()` method with `lca: null` should enter the target and resolve its initial child, which matches current behavior.

However, for **deep initial hierarchies** (e.g., initial state is `A.A1.A1a`), the current code enters `A` and `A1a` but misses `A1`. This is a related bug that should be fixed in the same change. When `lca` is null and the target is compound, we should enter all states from the target down to the resolved initial state.

---

## Detailed Call Site Audit

Every place that calls `runExitActions()` or `runEntryActions()` must be reviewed:

### Exit Call Sites

| Location | Line | Current Behavior | Change Needed |
|---|---|---|---|
| `transition()` | 2964 | `source->runExitActions()` | Replace with exit set walk |
| `transitionParallelState()` | 2739 | `sourceState->runExitActions()` | Replace with exit set walk (within region) |
| `exitParallelStateAndTransitionToTarget()` | 2587-2608 | Walks active states + parallel state | May need refinement for deep region hierarchies |
| `StateDefinition::runExitActions()` | 667 | Single state exit | No change (this IS the single-state method) |

### Entry Call Sites

| Location | Line | Current Behavior | Change Needed |
|---|---|---|---|
| `enterState()` | 1199-1202 | Two-level entry (target + initial child) | Replace with entry set walk |
| `enterParallelState()` | 566 | Parallel state entry + region initials | May need refinement |
| `enterStateInParallelRegion()` | 1372+ | Single region state entry | May need entry set for deep regions |
| `getInitialState()` | 474 | Calls enterState() | Pass lca: null (preserves behavior, but see deep initial hierarchy note) |
| `transitionToFireAndForgetTarget()` | 1354 | Calls enterState() | Pass lca: null or computed LCA |

---

## Test Cases

### Un-Skip Existing Tests

**Test #2: Deep hierarchy exit ordering** (`ActionOrderingTest.php` line 73-134)
- Machine: `A.A1.A1a -> B` with exit actions on A1a, A1, A
- Expected: `exit:A1a, exit:A1, exit:A, transition:A->B, entry:B`
- Remove `.skip()` annotation

**Test #3: Sibling transition** (`ActionOrderingTest.php` line 142-198)
- Machine: `P.child_a -> P.child_b` with entry/exit on P and children
- Expected: Parent NOT exited/re-entered; only `exit:child_a, transition, entry:child_b`
- Remove `.skip()` annotation

### Verify Existing Test Still Passes

**LcaTransitionTest.php** — Already tests sibling transition without parent exit/entry.

### New Test Cases to Add

#### 1. Four-Level Deep Hierarchy

```php
it('exits 4-level deep hierarchy inner-to-outer', function (): void {
    // A.A1.A1a.A1a_i -> B
    // Expected: exit:A1a_i, exit:A1a, exit:A1, exit:A, transition, entry:B
});
```

#### 2. Cross-Branch Cousin Transition

```php
it('handles cross-branch cousin transition with correct exit and entry sets', function (): void {
    // root.A.A1 -> root.B.B1
    // LCA = root
    // Expected: exit:A1, exit:A, transition, entry:B, entry:B1
});
```

#### 3. Transition from Descendant to Ancestor

```php
it('exits descendant states when transitioning to ancestor', function (): void {
    // A.A1.A1a -> A (self-transition on A from within A1a)
    // LCA = A's parent (root)
    // Expected: exit:A1a, exit:A1, exit:A, transition, entry:A, entry:A.initial
});
```

#### 4. Transition from Ancestor to Descendant

```php
it('re-enters hierarchy when transitioning from ancestor to descendant', function (): void {
    // A (currently in A.A1) -> A.A2.A2a
    // LCA = root (since A -> A.A2.A2a, A is both source and ancestor of target)
    //   Actually: if handler is on A, source=A, target=A.A2.A2a
    //   LCA = A's parent (since A must be exited for external transition)
    // Expected: exit:A1, exit:A, transition, entry:A, entry:A2, entry:A2a
});
```

#### 5. LCA with Parallel State Boundary

```php
it('computes LCA correctly when parallel state is in hierarchy', function (): void {
    // parallel.regionA.stateX -> non_parallel_state
    // LCA should be the common ancestor above the parallel state
    // Expected: exit:stateX, exit:regionA, exit:parallel, transition, entry:non_parallel_state
});
```

#### 6. @always Chain Crossing Hierarchy Levels

```php
it('follows LCA exit/entry through @always chain across hierarchy levels', function (): void {
    // A.A1 -> B (@always) -> C.C1
    // Hop 1: LCA(A1, B) exit:A1, exit:A, transition, entry:B
    // Hop 2: LCA(B, C1) exit:B, transition, entry:C, entry:C1
});
```

#### 7. Deep Initial State Entry

```php
it('enters all intermediate compound states during initialization', function (): void {
    // Machine with initial state A.A1.A1a
    // Expected on init: entry:A, entry:A1, entry:A1a (all intermediate compounds entered)
});
```

#### 8. Sibling Transition Within Compound (Not Root)

```php
it('does not exit grandparent during sibling transition within nested compound', function (): void {
    // G.P.child_a -> G.P.child_b
    // LCA = P (shared parent)
    // Expected: exit:child_a, transition, entry:child_b
    // G and P are NOT exited or re-entered
});
```

#### 9. Exit Actions with Machine Delegation Cleanup

```php
it('cleans up active children for all exited states in hierarchy', function (): void {
    // A.A1 has a running child machine. Transition A.A1 -> B
    // A1's child machine should be cancelled during exit cascade
});
```

#### 10. Exit/Entry Events in History

```php
it('records STATE_EXIT_START and STATE_EXIT_FINISH for each exited state', function (): void {
    // Verify internal event history shows exit events for every state in the exit set
});
```

---

## Risk Assessment

### Behavioral Changes

1. **States that previously were NOT exited will now be exited.** Any ancestor compound states between the active state and the LCA will now run their exit actions. If existing machines have exit actions on compound states that are not currently running, those will start running.

2. **States that previously were NOT entered will now be entered.** Ancestor compound states between the LCA and the target will now run entry actions. If existing machines have entry actions on compound states above the target, those will start firing during transitions.

3. **Exit ordering changes.** Currently, only the source state is exited. After the fix, the active atomic state is exited first (not the source). For transitions where the handler is on an ancestor, the active state was never exited before — now it will be.

### Which Existing Tests Might Break

1. **`tests/Features/ActionOrderingTest.php`**: Tests #2 and #3 will be un-skipped and should pass. Other tests (#1, #4-#10) should continue to pass since they involve flat transitions where the LCA computation produces the same result as current behavior.

2. **`tests/Features/LcaTransitionTest.php`**: This test currently passes because it transitions between siblings where the handler is defined on the child itself (not the parent). It should continue to pass.

3. **`tests/Definition/RootEntryExitTest.php`**: Root entry/exit tests should be unaffected — root lifecycle actions use a separate code path (`runRootLifecycleActions`).

4. **`tests/Definition/HierarchyTest.php`**: These test state resolution (transition to/from child states). They don't test exit/entry action ordering. Should be unaffected.

5. **Parallel state tests**: The parallel exit path (`exitParallelStateAndTransitionToTarget`) already walks multiple states. However, it may need updating if regions have deep hierarchies.

6. **Machine delegation tests**: If any test has exit actions on compound states above a delegation state, those exit actions will now fire during transitions. Need to audit delegation test stubs.

7. **Compound `@done` tests**: The `processCompoundOnDone` flow triggers transitions. If the compound state has exit actions that were not previously executed during `@done` transitions, behavior will change.

### Migration Path

1. **Non-breaking for machines without hierarchical exit/entry actions.** Most machines define exit/entry actions on atomic states only. For these, the LCA change adds computation cost but no behavioral change.

2. **Potentially breaking for machines with exit/entry actions on compound states.** These are relatively rare but must be audited. The fix makes behavior *correct* per SCXML, so the "break" is actually a bug fix.

3. **Recommended migration strategy:**
   - Add a feature flag (config `machine.lca_aware_exit_entry`, default `true`) for rollback safety during initial deployment
   - When the flag is `false`, use the old single-state exit behavior
   - Remove the flag in the next major version

4. **Deprecation of wrong behavior:** Document that the single-state exit was a known deviation from SCXML, now fixed.

### Performance Impact

- LCA computation: O(d) where d is hierarchy depth (typically 2-5). Negligible.
- Exit/entry set walks: O(d) iterations with O(1) per state. Negligible.
- Additional `runExitActions`/`runEntryActions` calls: proportional to hierarchy depth. Each call involves iterating over the state's exit/entry action arrays. This is the same cost as the existing single-state calls, just repeated for each level.

---

## Implementation Sequence

1. Add `computeLCA()`, `computeExitSet()`, `computeEntrySet()` methods to `MachineDefinition`
2. Add `?StateDefinition $lca = null` parameter to `enterState()`
3. Modify `transition()` exit protocol to use exit set
4. Modify `transition()` to pass LCA to `enterState()`
5. Modify `enterState()` to use entry set when LCA is provided
6. Modify `transitionParallelState()` exit protocol similarly
7. Review `exitParallelStateAndTransitionToTarget()` for deep region hierarchies
8. Un-skip tests #2 and #3 in `ActionOrderingTest.php`
9. Add all new test cases listed above
10. Run full quality gate (`composer quality`)
11. Audit existing test suite for regressions
12. Review compound `@done`, machine delegation, and parallel edge cases

---

## Open Questions

1. **Internal transitions:** SCXML supports `type="internal"` transitions where the source state is the LCA (no exit/entry of source). EventMachine does not currently support internal transitions. Should this spec include adding `internal` transition support, or should that be a separate spec?

   **Recommendation:** Separate spec. Internal transitions are a distinct feature. This spec focuses on making external transitions correct.

2. **Root state as LCA:** When the LCA computation reaches the root (`$this->root`), should root exit/entry lifecycle actions fire? Currently root exit/entry are handled separately in `getInitialState()` and the final-state block. They should NOT fire during transitions — they are machine lifecycle hooks, not state exit/entry.

   **Recommendation:** The exit/entry set walks should stop at the root (don't include root in exit/entry sets). Root lifecycle remains separate.

3. **`exitParallelStateAndTransitionToTarget` refactor:** This method already does multi-state exit but doesn't use the LCA pattern. Should it be refactored to use the same `computeExitSet` method?

   **Recommendation:** Yes, for consistency. The parallel escape exit should: (a) compute exit set for each active atomic state up to the parallel state, (b) exit the parallel state itself, (c) continue exiting up to the LCA if the target is outside the parallel state's parent.

4. **Deep initial state entry:** The initial entry path (`getInitialState()` -> `enterState()`) currently enters only the target and its initial child. For a machine with `initial: 'A.A1.A1a'`, intermediate compounds A and A1 are not entered. Should this be fixed in this spec?

   **Recommendation:** Yes. It is the same underlying issue (missing hierarchical entry). Use `lca: null` to signal "enter from root down", and compute the entry set as all states from root to the resolved initial state.
