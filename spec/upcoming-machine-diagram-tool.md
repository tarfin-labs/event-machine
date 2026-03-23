# EventMachine System Visualizer — Interactive Multi-Machine Diagram Tool

**Status:** Spec Ready — Overnight Build Task
**Author:** Claude (spec), deligoez (vision + research)
**Date:** 2026-03-24
**Scope:** Interactive diagram tool for EventMachine — single machines AND multi-machine systems with inter-machine communication, context flow, delegation, parallel states

---

## Problem

EventMachine systems grow beyond single machines quickly. A typical order workflow has 4-6 machines communicating via delegation, `sendTo`, `raise`, and forwarded endpoints. Today there is **no way** to see the full picture:

1. **Stately Studio** doesn't understand EventMachine concepts — `@done`/`@fail`/`@timeout`, `machine` delegation, `sendTo`/`raise`, calculators, listeners, context flow, forwarded endpoints
2. **No multi-machine view exists anywhere** — not in Stately, Mermaid, D2, or any tool. Parent-child delegation, fire-and-forget, cross-machine communication are invisible
3. **No context data flow** — which context keys flow where, `with` mappings between parent/child
4. **Export-import friction** — must export JSON, open Stately, paste

### What Exists vs What We Need

| Tool | What It Does | Why Not Enough |
|------|-------------|----------------|
| XState/Stately | Single machine viz, actor model | Multi-machine view yok, EventMachine concepts invisible |
| Mermaid | Static state diagram | Single machine, no interactivity, layout limited |
| D2 + TALA | Text-to-diagram, good layout | Static SVG, no interactivity, TALA paid |
| React Flow | General node editor | Editor, not viewer; no state machine semantics |
| Matlab Stateflow | Industry standard statechart | Simulink-dependent, no web, expensive |

**Gap:** An interactive, web-based tool that shows multiple state machines as collapsible containers with their inter-machine connections — this does not exist.

---

## Vision

In an EventMachine system, multiple machines communicate:
- **Parent delegates to child** via `machine` key → child runs, `@done`/`@fail` fires back
- **Machines send events** via `sendTo`/`dispatchTo` → cross-machine event routing
- **Endpoints forward** to child machines via `forward` key → HTTP routes bridge machines
- **Context flows** between machines via `with` mappings

We want **one interactive diagram** showing all of this:
- Each machine is a **collapsible container**
- **Collapsed:** only ports visible (in: events it accepts, out: events it sends, delegation connections)
- **Expanded:** full internal state machine visible
- **Inter-machine arrows** show delegation, sendTo, forward connections
- **All layout is automatic** — ELK handles positioning, no manual placement

---

## Academic Foundations

This project sits at the intersection of several traditions — we borrow the best ideas:

| Tradition | What We Take | What We Skip |
|-----------|-------------|-------------|
| **Harel Statecharts (1987)** | Hierarchy, compound states, orthogonal regions, visual formalism | Broadcast semantics (we use explicit sendTo) |
| **STATEMATE** | Three-view approach (behavior + structure + data flow) | Activity-Charts, Module-Charts (too complex) |
| **SDL (ITU)** | System→Block→Process hierarchy, channels between blocks | Formal verification, signal queues |
| **ROOM/Capsules** | Each capsule = actor with own FSM, port-based communication | Real-time scheduling |
| **IEC-61499** | Function blocks with event/data interfaces + internal ECC (state machine) | Industrial automation specifics |
| **SysML** | Block Definition Diagrams (structure) + State Machine Diagrams (behavior) | UML heavyweight notation |

**Our key insight:** We're building SysML's IBD (Internal Block Diagram = structure) and STM (State Machine Diagram = behavior) **merged into one interactive view**, tailored for EventMachine's specific concepts.

---

## Architecture

### Single HTML File — No Build Step

```
php artisan machine:diagram OrderMachine PaymentMachine ShippingMachine
    │
    ▼
┌─────────────────┐
│  DiagramCommand  │  Resolves machine classes, calls xstate export
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  XState JSON     │  Existing machine:xstate --stdout output
│  (per machine)   │  Already contains states, transitions, guards,
│                  │  actions, invoke, context, behavior catalog
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  HTML Template   │  Self-contained HTML with embedded:
│  Generator       │  - ELK.js (layout engine, ~200KB inlined)
│                  │  - d3-zoom (pan/zoom only, ~10KB inlined)
│                  │  - Machine JSON data (injected)
│                  │  - Vanilla JS renderer + interactivity
└────────┬────────┘
         │
         ▼
   diagram.html (opens in browser, works offline)
```

**Why no React/Vue/npm:**
- Zero dependency = works forever, no `node_modules` rot
- Any team member opens HTML in browser — done
- `php artisan machine:diagram` generates it — one command

**Why ELK.js (not dagre):**
- Native compound/nested node support (dagre has none)
- Port support — nodes can have connection points on specific sides
- Orthogonal, spline, polyline edge routing
- Multiple layout algorithms (layered, force, stress)
- Actively maintained by academic team at Kiel University
- Perfect for hierarchical state machines with nesting

### Runtime Architecture Inside HTML

```
Machine JSON(s)
    │
    ▼
┌──────────────┐
│  JSON → ELK   │  Transform XState JSON to ELK graph format
│  Transformer  │  Each machine = compound node (children = states)
│               │  Machine events = ports on compound node
│               │  invoke.src = inter-machine edges
│               │  Collapsed machines = fixed-size node with ports only
└──────┬───────┘
       │
       ▼
┌──────────┐
│  ELK.js  │  Compute layout (coordinates for every element)
│  Layout  │  Algorithm: elk.layered (best for state machines)
└────┬─────┘
     │
     ▼
┌───────────────┐
│  SVG Renderer │  ELK output → SVG elements
│               │  States = rounded rects (color-coded by type)
│               │  Transitions = bezier paths + arrow markers
│               │  Machines = container rects with header + ports
│               │  Labels = event names, guard conditions, actions
└──────┬────────┘
       │
       ▼
┌──────────────────┐
│  Interactivity   │  d3-zoom (pan/zoom)
│  Layer           │  Click to collapse/expand machines
│                  │  Click state → detail panel
│                  │  Click transition → event/guard/action details
│                  │  Re-layout on collapse/expand (ELK re-called)
└──────────────────┘
```

---

## Data Source: XState JSON (Already Exists)

The existing `machine:xstate` command exports everything we need. The `meta.eventMachine` namespace carries EventMachine-specific data.

### What XState export already provides:
- State hierarchy (compound, parallel, final)
- Transitions with events, guards, actions
- Context schema (including typed ContextManager extraction)
- Invoke (machine delegation) with src, input, onDone, onError
- Timer delays (`after`)
- Fire-and-forget metadata
- `@timeout` in meta
- Behavior catalog in `meta.eventMachine` (guards, actions, calculators, events)
- Event payload schemas (from validation rules)
- `requiredContext` per behavior

### What needs to be added to XState export (Phase 3+):
- Listener definitions (`listen` key)
- Endpoint definitions
- Schedule definitions
- `sendTo`/`dispatchTo` references in actions (requires annotation or static analysis)

**Strategy:** Phase 1-2 work entirely with existing XState JSON output. No changes to `ExportXStateCommand` needed initially.

### ELK Graph Mapping

Each machine becomes an ELK compound node:

```json
{
  "id": "root",
  "layoutOptions": {
    "elk.algorithm": "layered",
    "elk.direction": "RIGHT",
    "elk.spacing.nodeNode": "40",
    "elk.layered.spacing.nodeNodeBetweenLayers": "60"
  },
  "children": [
    {
      "id": "order_workflow",
      "layoutOptions": {
        "elk.algorithm": "layered",
        "elk.direction": "DOWN",
        "elk.padding": "[top=40,left=20,bottom=20,right=20]"
      },
      "labels": [{ "text": "OrderWorkflowMachine" }],
      "ports": [
        { "id": "order_workflow.in.ORDER_SUBMITTED", "properties": { "port.side": "WEST" } },
        { "id": "order_workflow.out.@done", "properties": { "port.side": "EAST" } },
        { "id": "order_workflow.invoke.PaymentMachine", "properties": { "port.side": "SOUTH" } }
      ],
      "children": [
        {
          "id": "order_workflow.validating",
          "labels": [{ "text": "validating" }],
          "width": 160, "height": 60,
          "properties": { "type": "invoke", "invoke.src": "ValidationMachine" }
        },
        {
          "id": "order_workflow.processing_payment",
          "labels": [{ "text": "processing_payment" }],
          "width": 160, "height": 60
        }
      ],
      "edges": [
        {
          "id": "order_workflow.e1",
          "sources": ["order_workflow.validating"],
          "targets": ["order_workflow.processing_payment"],
          "labels": [{ "text": "@done / storeValidationResult" }]
        }
      ]
    },
    {
      "id": "payment_machine",
      "labels": [{ "text": "PaymentMachine" }],
      "ports": [],
      "children": []
    }
  ],
  "edges": [
    {
      "id": "delegation.1",
      "sources": ["order_workflow.invoke.PaymentMachine"],
      "targets": ["payment_machine"],
      "labels": [{ "text": "with: [order_id, total_amount]" }]
    }
  ]
}
```

### Collapse/Expand Mechanism

- **Collapsed:** Machine node's `children` and internal `edges` removed. Node has fixed size (just header + ports). External edges remain connected to ports.
- **Expanded:** Machine node's children and internal edges restored. ELK re-computes layout.
- **Default:** Multi-machine view starts collapsed. Single-machine view starts expanded.
- **Animation:** SVG transition for smooth collapse/expand (optional, nice-to-have).

---

## Artisan Command: `machine:diagram`

```bash
# Single machine
php artisan machine:diagram App\\Machines\\OrderMachine

# Multi-machine system view
php artisan machine:diagram App\\Machines\\OrderMachine App\\Machines\\PaymentMachine App\\Machines\\ShippingMachine

# Output to specific file
php artisan machine:diagram App\\Machines\\OrderMachine --output=order-diagram.html

# With current state overlay (debugging)
php artisan machine:diagram App\\Machines\\OrderMachine --state='{"value":"processing_payment"}'

# From file path
php artisan machine:diagram app/Machines/OrderMachine.php

# All machines (auto-discover via MachineDiscovery)
php artisan machine:diagram --all

# Stdout (pipe to file or open directly)
php artisan machine:diagram App\\Machines\\OrderMachine --stdout
```

The command:
1. Resolves machine class(es) — supports FQCN, file paths, or `--all`
2. For each machine: calls `ExportXStateCommand` logic to get XState JSON
3. Detects inter-machine relationships from `invoke.src` references
4. Injects all machine JSONs into the HTML template
5. Embeds ELK.js and d3-zoom inline (from vendored copies, no CDN at runtime)
6. Writes HTML file and optionally opens browser (`--open` flag or auto-detect)

---

## UI Layout

```
┌──────────────────────────────────────────────────────────────┐
│  EventMachine System Visualizer    [Collapse All] [Expand All]│
│  OrderWorkflow + PaymentMachine     [Theme] [Export] [Fit]   │
├────────────┬─────────────────────────────────────────────────┤
│            │                                                  │
│  Behavior  │              SVG Diagram Area                    │
│  Catalog   │              (pan + zoom via d3-zoom)            │
│            │                                                  │
│ ────────── │   ┌─ OrderWorkflowMachine ─────────────────┐    │
│ Actions    │   │  ●→ [validating]  ─@done→  [processing] │    │
│  sendEmail │   │      invoke:       [isValid]  entry/    │    │
│  logPaymt  │   │      Validation     ↓        logStart   │    │
│ ────────── │   │                  [completed]             │    │
│ Guards     │   └──────────┬─────────────────────────────┘    │
│  isValid   │              │ with: [order_id, total]           │
│  isCharged │              ▼                                   │
│ ────────── │   ┌─ PaymentMachine (collapsed) ──────────┐     │
│ Events     │   │  ▸ IN: CHARGE, REFUND                  │     │
│  ORDER_SUB │   │  ◂ OUT: @done, @fail                   │     │
│  CANCEL    │   └─────────────────────────────────────────┘    │
│ ────────── │                                                  │
│ Calculators│                                                  │
│  orderTotal│                                                  │
│            │                                                  │
├────────────┴─────────────────────────────────────────────────┤
│  Detail Panel (click state/transition)                        │
│  ┌─ State: processing_payment ─────────────────────────────┐ │
│  │ Type: atomic  │  Entry: [logPaymentStart]                │ │
│  │ Context reads: payment_status, total_amount              │ │
│  │ Events: CANCEL_ORDER → cancelled [isNotYetCharged]       │ │
│  │ Timer: after 30s → payment_timeout                       │ │
│  └──────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────┘
```

---

## Visual Design

### State Types — Color Coding

| Element | Fill | Border | Meaning |
|---------|------|--------|---------|
| Atomic state | `#e8f4fd` light blue | `#90caf9` | Regular state |
| Compound state | `#f5f5f5` light gray | `#bdbdbd` | Container with children |
| Parallel state | `#fff3e0` light orange | `#ffcc80` dashed | Concurrent regions |
| Final state | `#e8f5e9` light green | `#81c784` double | Terminal state |
| Active state (current) | `#bbdefb` medium blue | `#42a5f5` thick | Current state overlay |
| Invoke state | `#f3e5f5` light purple | `#ce93d8` | Machine delegation |
| Machine container | `#fafafa` near-white | `#616161` | Machine boundary |

### Transition Types — Line Styles

| Transition | Line Style | Color | Label Format |
|-----------|-----------|-------|-------------|
| Event-triggered | Solid arrow | `#333` dark gray | `EVENT_NAME / actionName` |
| `@always` | Dashed arrow | `#999` gray | `always [guardName]` |
| `@done` | Solid thick arrow | `#4caf50` green | `@done / action` |
| `@fail` | Solid thick arrow | `#f44336` red | `@fail` |
| `@timeout` | Dotted arrow | `#ff9800` orange | `@timeout` |
| Timer (`after`) | Dot-dash arrow | `#ff9800` orange | `after 30s` |
| Guarded | Solid + badge | inherited | `[guardName]` bracket |
| Self-transition | Loop arrow | inherited | Same as event |
| Inter-machine delegation | Thick dashed | `#7b1fa2` purple | `with: [key1, key2]` |

### Notation (Inspired by Harel/SysML, Adapted for Readability)

| Concept | Symbol | Notes |
|---------|--------|-------|
| Initial state | `●→` filled circle with arrow | Standard Harel notation |
| Final state | `◉` double circle | Standard |
| Compound state | Rounded rect container | Children nested inside |
| Parallel regions | Dashed horizontal separator | Inside parallel container |
| Machine port (in) | `▸` small triangle on left border | Events machine accepts |
| Machine port (out) | `◂` small triangle on right border | Events machine emits |
| Invoke badge | `⎔` machine icon inside state | Machine delegation |
| Fire-and-forget | `↗` arrow-away icon | No @done expected |
| Timer | `⏱` clock icon | After/every delay |
| Queue | `≡` queue icon | Async processing |

---

## Features — Phased Implementation

### Phase 1: Core Diagram (Single Machine MVP)

#### 1.1 ELK Layout + SVG Rendering
- [ ] Set up HTML template with inlined ELK.js
- [ ] Transform XState JSON → ELK graph JSON
- [ ] Render ELK layout output as SVG
- [ ] Atomic states as rounded rectangles with name labels
- [ ] Transitions as bezier/polyline paths with arrow markers
- [ ] Event labels on transitions

#### 1.2 State Hierarchy
- [ ] Compound states as container rects with nested children
- [ ] Parallel states with dashed border + region separators
- [ ] Final states with double border circle
- [ ] Initial state indicator (filled circle + arrow)

#### 1.3 Transition Details
- [ ] Guard names in `[brackets]` on transitions
- [ ] Action names after `/` on transitions
- [ ] `@always` transitions as dashed arrows
- [ ] Multi-branch (guarded) transitions — multiple arrows per event
- [ ] Self-transitions (loop back to same state)
- [ ] Calculator names on transitions (distinct visual from actions)

#### 1.4 Entry/Exit Actions
- [ ] Entry actions shown inside state box (top: `entry / actionName`)
- [ ] Exit actions shown inside state box (bottom: `exit / actionName`)

#### 1.5 Basic Interactivity
- [ ] Pan (click-drag) and zoom (mouse wheel) via d3-zoom
- [ ] Click state → detail panel (bottom or sidebar)
- [ ] Click transition → detail panel
- [ ] Collapse/expand compound states → ELK re-layout

#### 1.6 Context Panel
- [ ] Sidebar showing context schema (keys, types, defaults)
- [ ] Typed ContextManager properties extracted

**Phase 1 Exit Criteria:** `php artisan machine:diagram TrafficLightsMachine` produces a correct, interactive HTML file.

### Phase 2: EventMachine-Specific Features

#### 2.1 Machine Delegation (invoke)
- [ ] Invoke states get purple background + machine icon
- [ ] `invoke.src` shown as label
- [ ] `onDone` transitions styled green, `onError` styled red
- [ ] `with`/input mapping on hover (which context keys transfer)
- [ ] Fire-and-forget badge
- [ ] `@timeout` transitions with clock icon

#### 2.2 Multi-Machine System View
- [ ] Multiple machines as separate ELK compound nodes
- [ ] Collapse/expand each machine independently
- [ ] Collapsed: header + ports only (in/out events, delegation connections)
- [ ] Expanded: full internal state machine
- [ ] Inter-machine edges from `invoke.src` references (auto-detected)
- [ ] `with` context mapping labels on delegation edges
- [ ] "Collapse All" / "Expand All" buttons
- [ ] Default: multi-machine starts collapsed, single-machine starts expanded

#### 2.3 Port-Based Machine Interface
- [ ] Each machine shows input ports (events it handles) on left border
- [ ] Each machine shows output ports (@done, @fail, sent events) on right border
- [ ] Delegation ports on bottom border
- [ ] Ports visible even when machine is collapsed
- [ ] Cross-machine edges connect port-to-port

#### 2.4 Timer Visualization
- [ ] `after` delays with clock icon and human-readable duration
- [ ] Timer transition lines styled in orange
- [ ] `every` (recurring) timers with repeat icon

#### 2.5 Event Payload Panel
- [ ] Click event label → popup showing payload schema
- [ ] Required vs optional fields
- [ ] Type badges (string, number, boolean, array)

#### 2.6 Behavior Catalog Panel
- [ ] Left sidebar listing all behaviors by category (actions, guards, calculators, events)
- [ ] Click behavior → highlight all states/transitions using it
- [ ] Show `requiredContext` per behavior
- [ ] Inline vs class-based indicator

**Phase 2 Exit Criteria:** Multi-machine system renders with delegation, ports, collapse/expand.

### Phase 3: Advanced Features

#### 3.1 Signal/Event Highlighting
- [ ] Hover on an event name → highlight all transitions using that event across all machines
- [ ] Hover on a context key → highlight all behaviors reading/writing it
- [ ] Hover on a delegation edge → highlight parent invoke state + child machine

#### 3.2 Listener Visualization
- [ ] `listen` hooks shown as small satellite nodes on states
- [ ] Entry/exit/transition listener types color-coded
- [ ] Queued listeners marked with queue icon
- [ ] Requires: adding listener export to `machine:xstate`

#### 3.3 Endpoint Overlay
- [ ] HTTP endpoint badges on diagram edge
- [ ] `GET /orders/{id}` style labels
- [ ] Lines from endpoint to the state/transition they trigger
- [ ] Forwarded endpoints connecting parent to child
- [ ] Requires: adding endpoint export to `machine:xstate`

#### 3.4 Simulation Mode
- [ ] "Play" button to step through the machine
- [ ] Available events shown as clickable buttons
- [ ] State transitions animate (highlight path)
- [ ] Context values update in real-time
- [ ] Event history panel
- [ ] Reset button

#### 3.5 Export & Polish
- [ ] Export as PNG/SVG
- [ ] Copy shareable URL with definition embedded (base64 in hash)
- [ ] Dark/light theme toggle
- [ ] Fit-to-screen button
- [ ] Minimap for large diagrams

**Phase 3 Exit Criteria:** Full-featured tool with simulation and export.

---

## Core UX Requirements (Non-Negotiable)

These are the absolute must-haves. Everything else is nice-to-have.

1. **Browser'da aç, zoom yaparak dolaş** — HTML'i açtığında hemen çalışan, smooth zoom + pan ile her detayı görebileceğin bir diagram. Mouse wheel zoom, click-drag pan, fit-to-screen button.

2. **Tüm machine definition görünür olmalı** — Bir state'e baktığında:
   - Hangi event'ler handle ediliyor
   - Hangi guard'lar var (guard adı transition üzerinde)
   - Hangi action'lar çalışıyor (entry, exit, transition)
   - Hangi calculator'ler var
   - Timer/delay bilgisi varsa
   - Context schema

3. **Child machine'ler collapsible olarak görünmeli** — Bir state `machine` key ile child machine çağırıyorsa:
   - O state'in içinde child machine'in diagram'ı görünmeli
   - Collapsible — kapatınca sadece child machine adı + port'ları kalır
   - Açınca child machine'in tüm iç state'leri, transition'ları görünür
   - Kaç tane child machine varsa hepsi böyle (recursive)
   - `with` mapping'i (hangi context key'ler akıyor) delegation edge üzerinde

4. **Hiçbir bilgi kaybolmamalı** — XState JSON'da ne varsa diyagramda görünmeli. Guard? Görünecek. Calculator? Görünecek. Fire-and-forget? Badge ile görünecek. @done.{state} routing? Her branch ayrı ok ile görünecek.

---

## Loop Progress Tracking

### Progress Log: `spec/diagram-build-progress.md`

Her loop iterasyonu bu dosyayı **okur ve günceller**. Bu dosya loop'lar arası state transfer mekanizmasıdır.

**Format:**

```markdown
# Diagram Tool Build Progress

## Current Status
- **Current Iteration:** 3
- **Last Completed:** Iteration 2 (State Types + Visual Design)
- **Last Updated:** 2026-03-24 23:45
- **HTML Status:** Working — opens in browser, shows colored state boxes

## Completed Iterations

### Iteration 1 — 2026-03-24 22:30
- Created DiagramCommand.php
- Created diagram-template.html with inlined ELK.js
- Basic boxes + arrows rendering
- Tested with TrafficLightsMachine: OK

### Iteration 2 — 2026-03-24 23:45
- Added compound state containers
- Color coding by state type
- Initial state indicator
- Tested with E2EBasicMachine (parallel): OK
- **Issue found:** Parallel region separator not rendering — deferred to Iteration 3

## Next Up
- Iteration 3: Transition Labels + Styling
- Focus: event labels, guard brackets, action names, @always dashed arrows

## Known Issues
- [ ] Parallel region separator not rendering (from Iteration 2)
- [ ] Self-transitions not handled yet (Iteration 3)

## Files Modified This Session
- src/Commands/DiagramCommand.php (created)
- resources/diagram-template.html (created, updated)
- src/MachineServiceProvider.php (modified — registered command)
```

### Loop Behavior Protocol

Each loop iteration MUST follow this exact sequence:

```
1. READ spec/diagram-build-progress.md
   → Determine current iteration number
   → Check known issues from previous iterations
   → Understand what's already built

2. READ spec/upcoming-machine-diagram-tool.md
   → Find the current iteration's task list
   → Understand acceptance criteria

3. DO THE WORK
   → Implement the current iteration
   → Fix any known issues from previous iterations if relevant
   → Test with appropriate stub machine

4. TEST
   → Generate HTML with artisan command (or manually if command not ready yet)
   → Verify HTML opens and works in browser context
   → Verify previous iterations' work still works (no regressions)

5. UPDATE spec/diagram-build-progress.md
   → Mark iteration as completed with timestamp
   → Note what was done, what was tested
   → Log any new issues found
   → Set "Next Up" to the next iteration
   → Update "HTML Status" with current state
   → List files modified

6. COMMIT (if meaningful checkpoint)
   → Stage changed files
   → Commit with descriptive message
```

**Critical rule:** If an iteration is too large to complete in one loop cycle, the loop should:
- Complete as much as possible
- Update progress with "Iteration X — IN PROGRESS (step Y of Z completed)"
- The next loop will continue from where it left off

---

## Loop Interval Decision

### Analysis

| Interval | Overnight (8h) | Iterations Possible | Risk |
|----------|----------------|--------------------|----|
| 3 min | ~160 fires | Most fires wasted waiting for prev work | Too aggressive, noisy |
| 5 min | ~96 fires | Good if iterations are quick | Some wasted fires |
| 10 min | ~48 fires | Balanced | Some idle gaps |
| 15 min | ~32 fires | Conservative | 15 min idle gaps add up |
| 20 min | ~24 fires | May not finish all 12 iterations | Too slow |

**How loop timing actually works:**
- Loop fires **between turns** — if Claude is still working, it waits
- If an iteration takes 25 min of Claude work, a 5-min loop fires immediately after (5 min already passed)
- If an iteration takes 5 min, a 5-min loop waits 5 more minutes
- Shorter interval = less idle time between iterations

**Recommendation: 5 minutes**

Why:
- Most iterations will take 15-40 min of actual Claude work → loop fires immediately after completion, ~0 idle time
- If an iteration finishes fast (e.g., a small fix), max 5 min idle before next fires
- Over 8 hours: effectively continuous work with minimal gaps
- Not so aggressive that it creates noise if something goes wrong

If you want to be more conservative: 10 min is also fine (max 10 min idle between iterations).

---

## Implementation Plan — Overnight Build Iterations

Each iteration produces a **working, openable HTML file**. Never a broken state between iterations.

### Iteration 1: Skeleton + ELK Proof of Concept (~30 min)
- Create `src/Commands/DiagramCommand.php` (artisan command)
- Create `resources/diagram-template.html`
- Inline ELK.js from vendored copy
- Hardcode TrafficLightsMachine JSON for testing
- Render basic boxes (state names) + arrows (transitions) via ELK → SVG
- **Checkpoint:** HTML opens in browser, shows boxes and arrows with correct layout

### Iteration 2: State Types + Visual Design (~30 min)
- Color-code states by type (atomic, compound, parallel, final)
- Rounded rectangles for states
- Compound states as containers with nested children
- Parallel states with dashed border
- Final states with double circle
- Initial state indicator
- **Checkpoint:** ChildDelegation or Parallel machine renders with proper hierarchy

### Iteration 3: Transition Labels + Styling (~30 min)
- Event name labels on arrows
- Guard names in `[brackets]`
- Action names after `/`
- `@always` as dashed arrows
- Multi-branch guarded transitions (multiple arrows from same event)
- Self-transitions (loop)
- Entry/exit actions inside state boxes
- **Checkpoint:** TrafficLightsMachine fully renders with all details

### Iteration 4: Pan/Zoom + Click Interactivity (~25 min)
- Inline d3-zoom (vendored, ~10KB)
- Mouse wheel zoom, click-drag pan
- Click state → detail panel at bottom
- Click transition → detail panel
- Detail shows: type, entry/exit actions, available events, context keys, timers
- **Checkpoint:** Fully interactive single-machine diagram

### Iteration 5: Context Panel + Behavior Catalog (~25 min)
- Left sidebar with context schema (keys, types, defaults)
- Behavior catalog below context (actions, guards, calculators, events)
- Click behavior → highlight usages in diagram
- **Checkpoint:** Phase 1 complete — single machine fully visualized

### Iteration 6: Invoke/Delegation Rendering (~30 min)
- Invoke states get purple styling + machine icon
- `invoke.src` label inside state
- `onDone` arrows green, `onError` arrows red
- `with`/input mapping tooltip
- Fire-and-forget badge
- `@timeout` with clock icon
- **Checkpoint:** ChildDelegation machines render correctly

### Iteration 7: Multi-Machine Compound Nodes (~35 min)
- Accept multiple machine JSONs
- Each machine → ELK compound node with header
- Port generation: input events → left ports, output events → right ports
- Auto-detect inter-machine references from `invoke.src`
- Draw delegation edges between machine compound nodes
- `with` mapping labels on edges
- **Checkpoint:** 2-machine system renders with connection

### Iteration 8: Collapse/Expand (~25 min)
- Click machine header → toggle collapse/expand
- Collapsed: fixed-size node, only header + ports visible
- Expanded: full internal state machine
- ELK re-layout on toggle (smooth transition if time allows)
- "Collapse All" / "Expand All" buttons in header
- Default: multi-machine starts collapsed
- **Checkpoint:** Collapse/expand works, ports remain connected

### Iteration 9: Timer + Event Payload Panels (~20 min)
- `after` delays with clock icon and duration label
- Timer transitions in orange
- Click event → popup with payload schema (required/optional, types)
- **Checkpoint:** Timer machines + event details working

### Iteration 10: Signal Highlighting + Polish (~25 min)
- Hover event name → highlight all related transitions
- Hover behavior in catalog → highlight usages
- Dark/light theme toggle
- Fit-to-screen button
- Export as SVG button
- Responsive layout
- **Checkpoint:** Phase 2 complete — polished multi-machine tool

### Iteration 11: Simulation Mode (~40 min)
- "Play" button enters simulation
- Available events shown as clickable buttons
- Click event → state transitions (highlight active state)
- Context values update in sidebar
- Event history log
- Reset button
- **Checkpoint:** Simulation working for single machine

### Iteration 12: Final QA (~30 min)
- Test with ALL stub machines (see table below)
- Fix edge cases
- Ensure `--all` flag works (auto-discover machines)
- Run `composer quality`
- **Checkpoint:** All done

---

## Test Machines

| Machine | Tests Feature |
|---------|--------------|
| `TrafficLightsMachine` | Basic: states, events, guards, actions, typed context |
| `ElevatorMachine` | Compound states, multiple events |
| `AlwaysGuardMachine` | @always transitions with guards |
| `GuardedMachine` | Multi-branch guarded transitions |
| `CalculatorMachine` | Calculators on transitions |
| `ChildDelegation/ParentOrderMachine` | Delegation, @done/@fail, with mapping |
| `ChildDelegation/AsyncParentMachine` | Async delegation, queue, timeout |
| `ChildDelegation/FireAndForgetParentMachine` | Fire-and-forget pattern |
| `ChildDelegation/DoneDotParentMachine` | @done.{state} routing |
| `ChildDelegation/ForwardParentMachine` | Forwarded endpoints |
| `Parallel/E2EBasicMachine` | Parallel states, regions, @done when all complete |
| `Parallel/E2EFailMachine` | Parallel with @fail |
| `Parallel/E2EThreeRegionMachine` | Three concurrent regions |
| `TimerMachines/*` | After/every timers |
| `Endpoint/*` | HTTP endpoints |
| `ListenerMachines/*` | Listener hooks |

---

## ELK.js Reference

**Key layout options to use:**

```javascript
// Root level (multi-machine system)
{
  "elk.algorithm": "layered",
  "elk.direction": "RIGHT",          // machines flow left-to-right
  "elk.spacing.nodeNode": "60",
  "elk.layered.spacing.nodeNodeBetweenLayers": "80"
}

// Machine level (internal state machine)
{
  "elk.algorithm": "layered",
  "elk.direction": "DOWN",           // states flow top-to-bottom
  "elk.padding": "[top=50,left=20,bottom=20,right=20]",  // room for header
  "elk.layered.nodePlacement.strategy": "NETWORK_SIMPLEX",
  "elk.edgeRouting": "SPLINES"       // smooth curves, not orthogonal
}

// Port options
{
  "port.side": "WEST",               // input ports on left
  "port.side": "EAST",               // output ports on right
  "port.side": "SOUTH"               // delegation ports on bottom
}
```

**Resources:**
- elkjs npm: https://github.com/kieler/elkjs
- ELK options: https://www.eclipse.org/elk/reference/options.html
- ELK algorithms: https://www.eclipse.org/elk/reference/algorithms.html
- ELK live editor: https://rtsys.informatik.uni-kiel.de/elklive/elkgraph.html

**d3-zoom:** https://github.com/d3/d3-zoom — only for pan/zoom, nothing else from D3.

---

## Files to Create/Modify

| File | Action | Purpose |
|------|--------|---------|
| `src/Commands/DiagramCommand.php` | Create | Artisan command |
| `resources/diagram-template.html` | Create | Self-contained HTML template (~5000+ lines) |
| `resources/vendor/elk.bundled.js` | Vendor | ELK.js bundled (~200KB, inlined at build) |
| `resources/vendor/d3-zoom.min.js` | Vendor | d3-zoom + d3-selection (~10KB, inlined) |
| `tests/Unit/DiagramCommandTest.php` | Create | Command tests |
| `src/MachineServiceProvider.php` | Modify | Register DiagramCommand |

---

## Non-Goals

- **No React, Vue, or any JS framework** — vanilla JS + SVG only
- **No build step** — no webpack, vite, npm in the output
- **No server component** — pure static HTML
- **No manual node placement** — layout is 100% automatic via ELK
- **No DSL** — input is EventMachine's own definition (via XState JSON export). The DSL IS the PHP machine definition.
- **No drag-and-drop editing** — this is a viewer, not an editor
- **No Stately Studio integration** — standalone replacement

---

## Loop Prompt Template

```
/loop 5m "Continue building the EventMachine diagram tool.

## Protocol — Follow This Exact Sequence

### Step 1: Read Progress
Read spec/diagram-build-progress.md to find:
- Which iteration you are on
- What was done in previous iterations
- Any known issues to fix

If spec/diagram-build-progress.md does not exist, create it and start at Iteration 1.

### Step 2: Read Spec
Read spec/upcoming-machine-diagram-tool.md — find the current iteration's requirements.

### Step 3: Do the Work
Implement the current iteration. Key rules:
- Each iteration MUST leave the HTML in a working, openable state
- Use ELK.js for layout (inlined, no CDN)
- Use d3-zoom for pan/zoom (inlined, no CDN)
- Use vanilla JS + SVG for rendering — NO React, Vue, or frameworks
- If the iteration is too large, do as much as you can and note partial progress

### Step 4: Test
Test the HTML output works. For iterations with the artisan command ready, run:
  php artisan machine:diagram [TestMachine] --stdout > /tmp/test-diagram.html
Verify no JS errors, correct rendering.

### Step 5: Update Progress
Update spec/diagram-build-progress.md with:
- What you completed (with timestamp)
- What you tested and the result
- Any issues found
- What the NEXT iteration should do
- Files you modified

### Step 6: Commit
Commit your work with a descriptive message.

## Architecture
- Single self-contained HTML file output (all JS inlined)
- Artisan command: php artisan machine:diagram
- Data source: existing machine:xstate --stdout JSON output
- Layout: ELK.js compound nodes, layered algorithm
- Rendering: Raw SVG elements
- Interactivity: d3-zoom (pan/zoom) + vanilla JS event handlers
- Progress: spec/diagram-build-progress.md (read/write every iteration)

## Core UX — Non-Negotiable
- Smooth zoom + pan in browser (mouse wheel + click-drag)
- ALL definition elements visible: states, transitions, guards, actions, calculators, events, context
- Child machines rendered as collapsible diagrams inside parent states
- No information loss — everything in XState JSON must be visible in the diagram"
```

---

## Success Criteria

1. `php artisan machine:diagram TrafficLightsMachine` produces a working HTML file
2. HTML opens in browser showing correct state diagram with ELK auto-layout
3. All states, transitions, guards, actions, calculators visible with correct styling
4. Compound and parallel states render with proper nesting
5. Machine delegation (invoke) states shown with distinct purple styling
6. Multi-machine view: `machine:diagram OrderMachine PaymentMachine` shows both with delegation edges
7. Collapse/expand works: collapsed machines show only ports, expanded show internals
8. Pan/zoom works smoothly
9. Click interactivity: state details, event payloads, behavior highlighting
10. Works fully offline — no external CDN/network calls
11. `composer quality` passes
12. Dark/light theme toggle works
