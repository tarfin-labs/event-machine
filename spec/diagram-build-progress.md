# Diagram Tool Build Progress

## Current Status
- **Current Iteration:** 6
- **Last Completed:** Iterations 4+5 merged (Interactivity + Behavior Catalog Polish)
- **Last Updated:** 2026-03-25 00:10
- **HTML Status:** Working — keyboard shortcuts, behavior highlighting, drag-vs-click differentiation, enhanced detail panel

## Completed Iterations

### Iteration 1 — 2026-03-24 23:15
- Created DiagramCommand + HTML template
- ELK layout, SVG rendering, dark theme, basic pan/zoom

### Iteration 2 — 2026-03-24 23:35
- ELK.js inlined for offline support
- Self-transitions, parallel separators, compound headers

### Iteration 3 — 2026-03-24 23:55
- Color-coded transition labels (tspan): event=blue, guard=yellow, calc=pink, action=green
- Label backgrounds, proper width calculation

### Iterations 4+5 — 2026-03-25 00:10
- **Pan vs click differentiation:** PanZoom tracks mouse movement distance; clicks only fire if mouse moved < 3px. No more accidental detail panel opens when panning.
- **Keyboard shortcuts:**
  - `Esc` — close detail panel + clear highlights
  - `+`/`-` — zoom in/out
  - `F` — fit to screen
  - `0` — reset zoom to 100%
  - Shortcut hint bar shown at bottom-left of canvas
- **Enhanced transition detail panel:** Calculators shown (pink), guards (yellow), actions (green) — color-matched to diagram labels
- **Behavior catalog click → highlight usages:**
  - Click a behavior in the sidebar → all states/transitions using it get yellow highlight
  - Click background or press Esc to clear
  - Active sidebar item gets yellow left border
- **requiredContext badge:** Behaviors with requiredContext show a "ctx" badge with tooltip
- **Class tooltip:** Hover over behavior item shows FQCN or "Inline behavior"
- Tested with TrafficLightsMachine and ParentOrderMachine

## Next Up
- Iteration 6: Invoke/Delegation Rendering
  - Invoke states already have purple styling and `invoke.src` label (from Iteration 1)
  - `onDone`/`onError` already styled green/red (from Iteration 1)
  - Focus: `with`/input mapping tooltip, fire-and-forget badge, `@timeout` icon
  - Also: more thorough testing with all ChildDelegation machines
  - **Checkpoint:** All delegation patterns render correctly

## Known Issues
- [ ] Self-transitions shown as text inside state — no loop-back arrows (acceptable)
- [ ] Testbench needs `CACHE_STORE=array` workaround
- [ ] Multi-machine view not implemented yet (Iteration 7)
- [ ] Label background width is estimated
- [ ] Highlight search is text-based (searches tspan content) — may have false positives for short names

## Files Modified This Session
- `src/Commands/DiagramCommand.php` (created, updated)
- `resources/diagram-template.html` (created, updated x4)
- `resources/vendor/elk.bundled.js` (vendored)
- `resources/vendor/d3-zoom.min.js` (vendored)
- `resources/vendor/d3-selection.min.js` (vendored)
- `src/MachineServiceProvider.php` (modified)
- `spec/upcoming-machine-diagram-tool.md` (created)
