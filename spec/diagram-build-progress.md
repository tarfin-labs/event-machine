# Diagram Tool Build Progress

## Current Status
- **Current Iteration:** 2
- **Last Completed:** Iteration 1 (Skeleton + ELK Proof of Concept)
- **Last Updated:** 2026-03-24 23:15
- **HTML Status:** Working — generates HTML, loads ELK.js from CDN, renders state boxes + arrows via ELK layout

## Completed Iterations

### Iteration 1 — 2026-03-24 23:15
- Created `src/Commands/DiagramCommand.php` — artisan command that:
  - Accepts one or more machine class paths
  - Uses ExportXStateCommand's buildMachineNode logic via reflection
  - Injects JSON into HTML template
  - Supports `--stdout`, `--output`, `--open` flags
- Created `resources/diagram-template.html` — self-contained HTML with:
  - Full dark theme UI (header, sidebar, canvas, detail panel)
  - PanZoom class (vanilla JS, no d3-zoom yet) — mouse wheel zoom + click-drag pan
  - XState JSON → ELK graph transformer
  - SVG renderer — states as rounded rects, transitions as smooth paths
  - State type styling (atomic=blue, compound=gray, parallel=orange, final=green, invoke=purple)
  - Entry/exit actions shown inside state boxes
  - Invoke badges (src label) on delegation states
  - Transition labels with event names, guard brackets, action names
  - Initial state indicator (filled circle)
  - Detail panel — click state/transition for details
  - Sidebar — context schema + behavior catalog (actions, guards, calculators, events)
  - Arrow markers color-coded by type (green=done, red=fail, orange=timeout)
- Vendored `resources/vendor/elk.bundled.js` (~1.5MB), `d3-zoom.min.js`, `d3-selection.min.js`
- Registered DiagramCommand in MachineServiceProvider
- Tested with TrafficLightsMachine: generates 44KB HTML, opens in browser
- Tested with ParentOrderMachine: generates 40KB HTML, invoke data present
- **Note:** ELK.js loaded from CDN fallback (not inlined yet). Works online but not offline.
- **Note:** d3-zoom not used yet — custom PanZoom class handles pan/zoom.
- **Note:** Testbench requires `CACHE_STORE=array` prefix due to in-memory SQLite.

## Next Up
- Iteration 2: State Types + Visual Design
  - Verify compound state rendering (nested children)
  - Parallel state region separators
  - Final state double circle (already styled but verify)
  - Polish visual design
  - Test with Parallel/E2EBasicMachine and ChildDelegation machines
  - Consider inlining ELK.js for offline support

## Known Issues
- [ ] ELK.js loaded from CDN — not offline-capable yet (will inline in future iteration)
- [ ] d3-zoom vendored but not used — custom PanZoom class used instead (simpler)
- [ ] Self-transitions not handled (no visual loop-back arrows)
- [ ] Multi-machine view not implemented yet (Iteration 7)
- [ ] Testbench command needs `CACHE_STORE=array` env var workaround

## Files Modified This Session
- `src/Commands/DiagramCommand.php` (created)
- `resources/diagram-template.html` (created)
- `resources/vendor/elk.bundled.js` (vendored)
- `resources/vendor/d3-zoom.min.js` (vendored)
- `resources/vendor/d3-selection.min.js` (vendored)
- `src/MachineServiceProvider.php` (modified — added DiagramCommand)
