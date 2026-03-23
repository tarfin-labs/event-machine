# Diagram Tool Build Progress

## Current Status
- **Current Iteration:** 4
- **Last Completed:** Iteration 3 (Transition Labels + Styling)
- **Last Updated:** 2026-03-24 23:55
- **HTML Status:** Working — color-coded transition labels (event=blue, guard=yellow, action=green, calculator=pink), label backgrounds for readability, proper label widths

## Completed Iterations

### Iteration 1 — 2026-03-24 23:15
- Created `src/Commands/DiagramCommand.php` — artisan command
- Created `resources/diagram-template.html` — dark theme, pan/zoom, ELK layout, SVG rendering
- Vendored ELK.js, d3-zoom, d3-selection
- Registered DiagramCommand in MachineServiceProvider

### Iteration 2 — 2026-03-24 23:35
- ELK.js inlining for offline support (~1.6MB output)
- Self-transitions rendered inside state boxes
- Parallel region separators (dashed orange lines)
- Compound state header backgrounds
- Node auto-sizing

### Iteration 3 — 2026-03-24 23:55
- **Color-coded transition labels using SVG `<tspan>`:**
  - Event name: blue (#c0d0ff) bold, or type-specific color (@done=green, @fail=red, @timeout=orange, @always=gray)
  - Guard: yellow (#ffc107) in [brackets]
  - Calculator: pink (#e91e63) as calc(name)
  - Action: green (#66bb6a) after /
- **Label backgrounds:** Semi-transparent dark rect behind labels for readability over edges
- **Proper label widths:** Label width in ELK calculated from full label text, not just event name
- **Color-coded self-transitions inside states:** Same tspan color scheme for self-transition event list
- **All edge types carry full properties:** guard, actions, calculators available for all edge types (always, done, fail, timeout) — used by both label rendering and detail panel
- Tested with 5 machines:
  - TrafficLightsMachine — self-transitions with color-coded events/guards/actions
  - GuardedMachine — multi-branch CHECK event (one with target, one self-transition)
  - ParentOrderMachine — invoke with @done/@fail color-coded green/red
  - ElevatorMachine — @always transition dashed with gray label
  - AlwaysGuardMachine — @always with guard [isAllowedGuard] + action, GO event with guard

## Next Up
- Iteration 4: Pan/Zoom + Click Interactivity
  - Pan/zoom already implemented (custom PanZoom class)
  - Click detail panel already implemented
  - **Focus this iteration on polish:**
    - Improve detail panel with more info (calculators, meta)
    - Add keyboard shortcuts (Escape to close detail, +/- zoom)
    - Ensure pan doesn't interfere with state click
    - Test interactivity across all machine types
  - Since Iterations 1-3 already covered most of Iteration 4's requirements, consider merging 4+5 (Context Panel + Behavior Catalog polish)
  - **Checkpoint:** Fully interactive single-machine diagram

## Known Issues
- [ ] Self-transitions shown as text inside state box — no loop-back arrows (acceptable UX)
- [ ] d3-zoom vendored but not used — custom PanZoom class works fine
- [ ] Testbench command needs `CACHE_STORE=array` env var workaround
- [ ] Multi-machine view not implemented yet (Iteration 7)
- [ ] Parallel region separators may not align perfectly with ELK layout (depends on region sizing)
- [ ] Label background width is estimated (character count * 6.5px) — may be slightly off for non-monospace fonts

## Files Modified This Session
- `src/Commands/DiagramCommand.php` (created, updated)
- `resources/diagram-template.html` (created, updated x3)
- `resources/vendor/elk.bundled.js` (vendored)
- `resources/vendor/d3-zoom.min.js` (vendored)
- `resources/vendor/d3-selection.min.js` (vendored)
- `src/MachineServiceProvider.php` (modified)
- `spec/upcoming-machine-diagram-tool.md` (created)
