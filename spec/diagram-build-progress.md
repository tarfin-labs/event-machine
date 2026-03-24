# Diagram Tool Build Progress

## Current Status
- **Current Iteration:** 11
- **Last Completed:** Iteration 10 (Signal Highlighting + Polish)
- **Last Updated:** 2026-03-25 01:30
- **HTML Status:** Working — light/dark theme toggle, SVG export, hover highlighting, sidebar toggle, CSS variables

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
- Collapse/expand machine containers

### Iteration 9 — 2026-03-25 01:15
- Timer formatting, event payload popup

### Iteration 10 — 2026-03-25 01:30
- **Light/Dark theme toggle:**
  - CSS custom properties (`:root` + `.light-theme` override)
  - All UI elements use variables: background, borders, text, states, edges
  - `T` keyboard shortcut or button to toggle
  - Light theme: white backgrounds, darker text, adapted state colors
- **SVG Export:**
  - "SVG" button in header
  - Clones SVG, sets proper viewBox, removes pan/zoom transform
  - Downloads as `<machine-name>.svg` file
  - Includes background color from current theme
- **Sidebar Toggle:** `S` key or button hides/shows sidebar for more diagram space
- **Hover Highlight:**
  - Hover over a transition edge → all transitions with same event name highlight blue
  - Uses `.hover-highlight` CSS class applied/removed on mouseover/mouseout
- **Updated keyboard shortcuts:** T (theme), S (sidebar) added to hint bar
- **Tested:** Single and multi-machine both work with all new features

## Next Up
- Iteration 11: Simulation Mode (optional — complex feature)
  - OR skip to Iteration 12: Final QA with all stub machines
  - Simulation mode is nice-to-have, QA is essential
  - **Recommend:** Skip simulation, go to QA

## Known Issues
- [ ] Self-transitions shown as text inside state — no loop-back arrows
- [ ] Testbench needs `CACHE_STORE=array` workaround
- [ ] Label background width estimated
- [ ] Light theme SVG colors may need tuning for some elements (state node fills use hardcoded colors in JS, not CSS variables yet)
- [ ] SVG export doesn't include CSS styles embedded — rendered SVG may look different

## Files Modified This Session
- `src/Commands/DiagramCommand.php` (created, updated)
- `resources/diagram-template.html` (created, updated x9)
- `resources/vendor/elk.bundled.js` (vendored)
- `resources/vendor/d3-zoom.min.js` (vendored)
- `resources/vendor/d3-selection.min.js` (vendored)
- `src/MachineServiceProvider.php` (modified)
