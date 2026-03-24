# Diagram Tool Build Progress

## Current Status
- **Current Iteration:** COMPLETE
- **Last Completed:** Iteration 11 — Final QA (skipped simulation, went straight to QA)
- **Last Updated:** 2026-03-25 01:45
- **HTML Status:** Production-ready — all 14 test machines pass, PHPStan clean, 100% type coverage

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
- Light/dark theme, SVG export, hover highlighting, sidebar toggle

### Iteration 11 (Final QA) — 2026-03-25 01:45
- **Bug fix:** `DiagramCommand` now uses `$machinePath::definition()` instead of `::create()` to avoid triggering entry actions (E2EFailMachine has ThrowRuntimeExceptionAction)
- **All 14 single-machine tests pass:**
  - TrafficLightsMachine, ElevatorMachine, AlwaysGuardMachine, GuardedMachine, CalculatorMachine
  - ParentOrderMachine, AsyncParentMachine, FireAndForgetParentMachine, DoneDotParentMachine
  - E2EBasicMachine, E2EFailMachine, E2EThreeRegionMachine
  - AfterTimerMachine, EveryTimerMachine
- **Multi-machine test passes:** ParentOrderMachine + ChildPaymentMachine
- **Quality gate:**
  - PHPStan: No errors
  - Type coverage: 100%
  - Pint: pass
  - Rector: clean (import reorder applied)
  - Unit tests: 1651 passed (96 E2E failures are pre-existing — require real MySQL/Redis)

## Feature Summary

| Feature | Status |
|---------|--------|
| ELK.js auto-layout | Done — inlined for offline |
| Pan/zoom | Done — mouse wheel + drag, keyboard +/- |
| State types (atomic, compound, parallel, final) | Done — color-coded |
| Transitions (event, @always, @done, @fail, @timeout, after) | Done — styled, labeled |
| Guards | Done — yellow [brackets] on transitions + detail panel |
| Actions | Done — green /name on transitions + entry/exit in states |
| Calculators | Done — pink calc(name) on transitions |
| Context panel | Done — sidebar with types and defaults |
| Behavior catalog | Done — click to highlight usages |
| Self-transitions | Done — shown as text list inside state |
| Invoke/delegation | Done — purple, src label, with mapping, queue/timeout |
| Fire-and-forget | Done — ↗ icon + orange badge |
| @timeout edges | Done — from state.meta.eventMachine.onTimeout |
| Timer formatting | Done — days/hours/min/sec with ⏱ icon |
| Event payload popup | Done — click event in sidebar |
| Multi-machine view | Done — compound nodes, delegation edges |
| Collapse/expand | Done — click header, re-layout |
| Light/dark theme | Done — CSS variables, T key |
| SVG export | Done — download button |
| Hover highlight | Done — same-event transitions |
| Sidebar toggle | Done — S key |
| Keyboard shortcuts | Done — Esc, +/-, F, T, S, 0 |
| Detail panel | Done — click state/transition |

## Not Implemented (Nice-to-Have)
- [ ] Simulation mode (play through machine)
- [ ] Listener visualization
- [ ] Endpoint overlay
- [ ] Minimap for large diagrams
- [ ] `--all` flag (auto-discover machines)
