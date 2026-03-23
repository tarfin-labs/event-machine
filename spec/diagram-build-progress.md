# Diagram Tool Build Progress

## Current Status
- **Current Iteration:** 8
- **Last Completed:** Iteration 7 (Multi-Machine Compound Nodes)
- **Last Updated:** 2026-03-25 00:45
- **HTML Status:** Working — multi-machine system view with compound nodes, delegation edges, merged sidebar

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
- Invoke delegation: fire-and-forget, @timeout, with mapping, queue/timeout info

### Iteration 7 — 2026-03-25 00:45
- **Multi-machine system view:**
  - `xstateToElkMulti()` wraps each machine as ELK compound node
  - Root layout: LEFT→RIGHT (machines side by side)
  - Internal layout: DOWN (states top-to-bottom within each machine)
  - Machine container rendering with header bar, name, background, separator
- **Inter-machine delegation edges:**
  - Auto-detects `invoke.src` references matching other machines' class basenames
  - Draws purple dashed arrow from invoke state to child machine compound node
  - Shows `with` mapping on edge label (e.g., `with: order_id, amount←total_amount`)
  - Purple arrowhead marker added
- **Merged sidebar for multi-machine:**
  - Context panel shows contexts grouped by machine name
  - Behavior catalog merges all behaviors from all machines
- **Single-machine view preserved:** No regression — `isMulti` flag routes to existing code
- **Tested:**
  - Single machine (TrafficLightsMachine): OK, no regression
  - 2-machine system (ParentOrderMachine + ChildPaymentMachine): delegation edge visible
  - 3-machine system (ParentOrder + ChildPayment + SimpleChild): all connected

## Next Up
- Iteration 8: Collapse/Expand
  - Click machine header to toggle collapse/expand
  - Collapsed: fixed-size node with name + ports
  - Expanded: full internal state machine
  - ELK re-layout on toggle
  - Default: multi-machine starts collapsed
  - **Checkpoint:** Collapse/expand works

## Known Issues
- [ ] Self-transitions shown as text inside state — no loop-back arrows
- [ ] Testbench needs `CACHE_STORE=array` workaround
- [ ] Label background width estimated
- [ ] Delegation edge target is machine compound node, not specific state (ELK routes to compound node boundary)
- [ ] Inter-machine edges may cross machine boundaries visually if ELK routing is imperfect

## Files Modified This Session
- `src/Commands/DiagramCommand.php` (created, updated)
- `resources/diagram-template.html` (created, updated x6)
- `resources/vendor/elk.bundled.js` (vendored)
- `resources/vendor/d3-zoom.min.js` (vendored)
- `resources/vendor/d3-selection.min.js` (vendored)
- `src/MachineServiceProvider.php` (modified)
