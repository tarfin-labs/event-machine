# Diagram Tool Build Progress

## Current Status
- **Current Iteration:** 9
- **Last Completed:** Iteration 8 (Collapse/Expand)
- **Last Updated:** 2026-03-25 01:00
- **HTML Status:** Working — collapse/expand machine containers, re-layout on toggle, Collapse All / Expand All buttons

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
- Invoke delegation: fire-and-forget, @timeout, with mapping

### Iteration 7 — 2026-03-25 00:45
- Multi-machine compound nodes, delegation edges, merged sidebar

### Iteration 8 — 2026-03-25 01:00
- **Collapse/expand machine containers:**
  - `collapsedMachines` Set tracks which machines are collapsed
  - Click machine header (▶/▼ indicator) to toggle
  - Collapsed nodes show: event port summary (IN/OUT events), state count
  - Expanded nodes show full internal state machine
  - `reLayoutMulti()` rebuilds ELK graph and re-renders on every toggle
  - Edge counter reset on re-layout to avoid stale IDs
- **Collapse All / Expand All buttons** in header bar (only shown in multi-machine mode)
- **Collapsed styling:** Semi-transparent background, port lines in gray
- **No single-machine regression** — collapse buttons hidden in single mode
- **Tested:**
  - Multi-machine (ParentOrder + ChildPayment): collapse/expand toggle works
  - Single machine (TrafficLights): no regression

## Next Up
- Iteration 9: Timer + Event Payload Panels
  - Timer machines test (after/every)
  - Event payload popup on click
  - Then Iteration 10: Polish + dark/light theme
- **Checkpoint:** Timer machines + event details working

## Known Issues
- [ ] Self-transitions shown as text inside state — no loop-back arrows
- [ ] Testbench needs `CACHE_STORE=array` workaround
- [ ] Label background width estimated
- [ ] Delegation edge targeting compound node boundary (not initial state)

## Files Modified This Session
- `src/Commands/DiagramCommand.php` (created, updated)
- `resources/diagram-template.html` (created, updated x7)
- `resources/vendor/elk.bundled.js` (vendored)
- `resources/vendor/d3-zoom.min.js` (vendored)
- `resources/vendor/d3-selection.min.js` (vendored)
- `src/MachineServiceProvider.php` (modified)
