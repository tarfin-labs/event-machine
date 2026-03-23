# Diagram Tool Build Progress

## Current Status
- **Current Iteration:** 7
- **Last Completed:** Iteration 6 (Invoke/Delegation Rendering)
- **Last Updated:** 2026-03-25 00:25
- **HTML Status:** Working — invoke states show src, with mapping, queue/timeout, fire-and-forget badge, @timeout edges

## Completed Iterations

### Iteration 1 — 2026-03-24 23:15
- Created DiagramCommand + HTML template, ELK layout, SVG rendering

### Iteration 2 — 2026-03-24 23:35
- ELK.js inlined, self-transitions, parallel separators

### Iteration 3 — 2026-03-24 23:55
- Color-coded transition labels (tspan), label backgrounds

### Iterations 4+5 — 2026-03-25 00:10
- Keyboard shortcuts, behavior highlighting, pan/click fix

### Iteration 6 — 2026-03-25 00:25
- **Invoke state rendering enhanced:**
  - `invoke.src` shown with hexagon icon (⬢) for managed, arrow (↗) for fire-and-forget
  - Fire-and-forget badge in orange: "(fire & forget)"
  - `with`/input mapping shown below src: `with: order_id, amount←total_amount`
  - Same-name mappings shown as just the key, renamed mappings show `child←parent` with arrow
  - Queue and timeout info: `queue: child-queue | timeout: 30s`
  - Node auto-sizes to fit invoke content
- **@timeout edges:** Generated from `state.meta.eventMachine.onTimeout`
  - Styled as orange dotted arrows with ⏱ clock icon
  - Carries guard/actions properties for detail panel
- **@done.{state} routing:** Multi-branch onDone with synthetic guards renders correctly (DoneDotParentMachine tested)
- **Tested with ALL 6 ChildDelegation machines:**
  - ParentOrderMachine — invoke + with mapping + @done/@fail
  - AsyncParentMachine — async invoke with queue
  - AsyncTimeoutParentMachine — @timeout edge from meta, queue + timeout info
  - FireAndForgetParentMachine — fire-and-forget badge, no @done
  - FireAndForgetTargetParentMachine — fire-and-forget with target
  - DoneDotParentMachine — @done.{state} multi-branch routing

## Next Up
- Iteration 7: Multi-Machine Compound Nodes
  - Accept multiple machine JSONs
  - Each machine as ELK compound node with header
  - Auto-detect inter-machine references from invoke.src
  - Draw delegation edges between machine compound nodes
  - **Checkpoint:** 2-machine system renders with connection

## Known Issues
- [ ] Self-transitions shown as text inside state — no loop-back arrows
- [ ] Testbench needs `CACHE_STORE=array` workaround
- [ ] Label background width is estimated
- [ ] Highlight search is text-based

## Files Modified This Session
- `src/Commands/DiagramCommand.php` (created, updated)
- `resources/diagram-template.html` (created, updated x5)
- `resources/vendor/elk.bundled.js` (vendored)
- `resources/vendor/d3-zoom.min.js` (vendored)
- `resources/vendor/d3-selection.min.js` (vendored)
- `src/MachineServiceProvider.php` (modified)
