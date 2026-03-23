# Diagram Tool Build Progress

## Current Status
- **Current Iteration:** 3
- **Last Completed:** Iteration 2 (State Types + Visual Design + ELK Inlining)
- **Last Updated:** 2026-03-24 23:35
- **HTML Status:** Working — fully offline (ELK.js inlined ~1.5MB), state types color-coded, self-transitions shown inside states, parallel region separators

## Completed Iterations

### Iteration 1 — 2026-03-24 23:15
- Created `src/Commands/DiagramCommand.php` — artisan command
- Created `resources/diagram-template.html` — dark theme, pan/zoom, ELK layout, SVG rendering
- Vendored `resources/vendor/elk.bundled.js`, `d3-zoom.min.js`, `d3-selection.min.js`
- Registered DiagramCommand in MachineServiceProvider
- Tested: TrafficLightsMachine, ParentOrderMachine — HTML generates, opens in browser

### Iteration 2 — 2026-03-24 23:35
- **ELK.js inlining:** DiagramCommand now inlines elk.bundled.js into the HTML via `/* __ELK_JS__ */` placeholder. Output is ~1.6MB but fully offline — no CDN needed.
- **Self-transitions:** Events without `target` (actions-only) now rendered inside state box below a separator line. Each shows event name + guard + actions.
- **Parallel state visual:** Parallel states get `‖ name (parallel)` header badge in orange. Dashed horizontal separators between regions.
- **Compound state visual:** Compound states get a darker header background for the state name area.
- **Node sizing:** States auto-size based on content (name length, entry/exit actions, self-transitions, invoke badge).
- **Detail panel:** Now shows self-transitions in the state detail view.
- Tested with 5 machines, all generate successfully:
  - TrafficLightsMachine (1.65MB) — self-transitions for MULTIPLY, INCREASE, DECREASE, etc.
  - E2EBasicMachine (1.65MB) — parallel state with region_a, region_b, nested children
  - ParentOrderMachine (1.65MB) — invoke delegation, @done/@fail transitions
  - GuardedMachine (1.65MB) — multi-branch guarded transitions
  - ElevatorMachine (1.65MB) — @always transitions

## Next Up
- Iteration 3: Transition Labels + Styling
  - Verify event labels, guard brackets, action names on arrows render correctly
  - `@always` as dashed arrows (already styled but verify with ElevatorMachine)
  - Multi-branch guarded transitions (GuardedMachine has CHECK event with 2 branches)
  - Self-transition loop arrows (currently shown inside state — could add optional loop arrow)
  - Entry/exit actions already handled — verify
  - **Checkpoint:** TrafficLightsMachine fully renders with all details visible

## Known Issues
- [ ] Self-transitions shown as text inside state box — no visual loop-back arrows (acceptable UX, text is clearer)
- [ ] d3-zoom vendored but not used — custom PanZoom class used instead (simpler, works fine)
- [ ] Testbench command needs `CACHE_STORE=array` env var workaround
- [ ] Multi-machine view not implemented yet (Iteration 7)
- [ ] ELK.js inlined makes HTML ~1.6MB — acceptable for dev tool

## Files Modified This Session
- `src/Commands/DiagramCommand.php` (created, updated — ELK inlining)
- `resources/diagram-template.html` (created, updated — self-transitions, parallel separators, compound headers)
- `resources/vendor/elk.bundled.js` (vendored)
- `resources/vendor/d3-zoom.min.js` (vendored)
- `resources/vendor/d3-selection.min.js` (vendored)
- `src/MachineServiceProvider.php` (modified — added DiagramCommand)
- `spec/upcoming-machine-diagram-tool.md` (created)
