# EventMachine Designer - Master Plan

> A visual state machine design tool for EventMachine, inspired by Harel's statecharts,
> informed by cognitive science, and built for an AI-pluggable future.

## Design Philosophy

EventMachine Designer follows the same principles as EventMachine itself:

| EventMachine Principle | Designer Equivalent |
|------------------------|---------------------|
| **Config-driven**: Define machines with arrays, not imperative code | **Schema-driven**: Editor capabilities come from the machine definition schema |
| **Behavior-pluggable**: Actions, guards, calculators are swappable | **AI-pluggable**: AI is just another client that uses the tool interface |
| **Event-driven**: Everything happens through events | **Event-driven**: Editor actions are events (`state_added`, `transition_created`, etc.) |
| **Persistent**: Machine state is stored and restorable | **Persistent**: Editor state (layout, preferences) is saved and restorable |

### Three Foundational Insights

**1. From Harel (1987):** Humans manage complexity through abstraction hierarchies.
The editor must let users view their machine at different levels of detail -
zooming in to see guard conditions, zooming out to see the overall shape.

**2. From Moody (2009):** Good visual design is cognitive engineering, not aesthetics.
Every visual choice (shapes, colors, positions) must reduce cognitive load,
not just look pretty.

**3. From Sketch.systems:** Separate thinking from implementing.
The editor must support "just sketching" without forcing users
to define every guard and action upfront.

---

## Architecture

```
                        Any AI Client
                    (Claude, GPT, Gemini,
                     local LLM, scripts)
                            |
                            | MCP Protocol / REST API
                            v
+---------------------------------------------------------------+
|                   EventMachine Designer                        |
|                                                                |
|  +----------------------------------------------------------+ |
|  | Tool Interface Layer                                      | |
|  |                                                            | |
|  | Read:     machine.get, state.get, analysis.get            | |
|  | Write:    state.add, transition.add, state.update         | |
|  | Analysis: reachability, deadlock, paths, guard_coverage   | |
|  | Generate: php_config, test_scenarios, behavior_stubs      | |
|  +----------------------------------------------------------+ |
|                            |                                   |
|  +----------------------------------------------------------+ |
|  | Frontend (React + TypeScript)                              | |
|  |                                                            | |
|  |  +------------+  +--------------+  +-------------------+  | |
|  |  | Canvas     |  | Inspector    |  | Code / Simulator  |  | |
|  |  | (React     |  | Panel        |  | Panel             |  | |
|  |  |  Flow +    |  |              |  | (Monaco)          |  | |
|  |  |  ELK.js)   |  |              |  |                   |  | |
|  |  +------------+  +--------------+  +-------------------+  | |
|  |                                                            | |
|  |  +------------------------------------------------------+ | |
|  |  | Machine Store (event-driven, undo/redo)               | | |
|  |  +------------------------------------------------------+ | |
|  +----------------------------------------------------------+ |
|                            |                                   |
|  +----------------------------------------------------------+ |
|  | JSON Schema & Serialization                                | |
|  |                                                            | |
|  | EventMachine Config JSON  <->  React Flow Nodes/Edges     | |
|  | Layout positions stored separately from machine logic     | |
|  +----------------------------------------------------------+ |
+---------------------------------------------------------------+
                            |
                            | JSON import/export
                            v
+---------------------------------------------------------------+
|  EventMachine Package (PHP/Laravel)                            |
|                                                                |
|  php artisan machine:export-json   ->  config.json             |
|  php artisan machine:import-json   <-  config.json             |
|  php artisan machine:export-schema ->  schema.json             |
+---------------------------------------------------------------+
```

### Key Architectural Decisions

**Standalone Web App** (not embedded in Laravel)
- No frontend dependency for a backend package
- Can be deployed independently (docs site, local dev server, or hosted)
- Laravel integration via artisan commands for import/export

**JSON as Intermediate Format**
- PHP array config is the source of truth in production
- JSON is the bridge between PHP and the visual editor
- `layout` key stores visual positions separately from machine logic
- Round-trip fidelity: JSON -> Visual -> JSON produces identical output

**AI as External Client, Not Built-in Feature**
- The editor exposes tools via MCP (Model Context Protocol)
- Any AI client connects to the editor as an MCP server
- A Claude Code skill describes how to interact with the editor
- Users bring their own AI - the editor doesn't care which one
- Non-AI scripts can use the same interface for automation

---

## Tool Interface

The editor exposes its capabilities as tools. These tools follow the MCP protocol,
making them accessible to any compatible AI client.

### Read Tools

| Tool | Description | Returns |
|------|-------------|---------|
| `designer.machine.get` | Get the full machine definition | Complete JSON config |
| `designer.machine.summary` | Get a high-level overview | State count, transition count, type |
| `designer.state.get` | Get details of a specific state | State config with children |
| `designer.state.list` | List all states with types | Array of state summaries |
| `designer.transition.get` | Get transition details | Guards, actions, calculators, target |
| `designer.transition.list` | List all transitions | Array of transition summaries |
| `designer.context.get` | Get the context schema | Key names, types, defaults |
| `designer.event.list` | List all event types | Event names with payload schemas |

### Write Tools

| Tool | Description | Parameters |
|------|-------------|------------|
| `designer.state.add` | Add a new state | `name`, `type?`, `parent?`, `position?` |
| `designer.state.remove` | Remove a state | `name` |
| `designer.state.update` | Update state properties | `name`, `properties` |
| `designer.state.move` | Reposition a state | `name`, `x`, `y` |
| `designer.transition.add` | Add a transition | `from`, `to`, `event`, `guards?`, `actions?` |
| `designer.transition.remove` | Remove a transition | `from`, `to`, `event` |
| `designer.transition.update` | Update transition details | `from`, `to`, `event`, `properties` |
| `designer.context.set` | Set context schema | `schema` |
| `designer.event.define` | Define an event type | `name`, `payload_schema?`, `validation?` |
| `designer.initial.set` | Set the initial state | `state_name` |
| `designer.layout.auto` | Trigger automatic layout | `algorithm?` |
| `designer.batch` | Apply multiple changes atomically | `operations[]` |

### Analysis Tools

| Tool | Description | Returns |
|------|-------------|---------|
| `designer.analyze.reachability` | Find unreachable states | List of unreachable state names |
| `designer.analyze.deadlocks` | Find deadlocked states | Non-final states with no outgoing transitions |
| `designer.analyze.determinism` | Check transition determinism | Ambiguous transitions (same event, no guards) |
| `designer.analyze.guard_coverage` | Check guard completeness | Multi-branch transitions missing fallback |
| `designer.analyze.context_usage` | Track context key usage | Which keys are read/written by which behaviors |
| `designer.analyze.event_coverage` | Event handling matrix | Which states handle which events |
| `designer.analyze.paths` | Enumerate execution paths | All paths between two states (or all paths) |
| `designer.analyze.all` | Run all analyses | Combined report |

### Generate Tools

| Tool | Description | Returns |
|------|-------------|---------|
| `designer.generate.php_config` | Generate PHP array config | PHP code string |
| `designer.generate.behavior_stubs` | Generate PHP class stubs | Array of file contents |
| `designer.generate.test_scenarios` | Generate Pest test scenarios | PHP test code |
| `designer.generate.plantuml` | Generate PlantUML diagram | `.puml` content |
| `designer.generate.mermaid` | Generate Mermaid diagram | Mermaid syntax |

---

## Visual Design Language

Based on Moody's Physics of Notations, each visual element has a specific,
cognitively distinct representation.

### State Types

```
 ●               Initial pseudo-state (filled black circle)
                  Always present, always points to the initial state

 ┌─────────────┐  Atomic State (rounded rectangle, single border)
 │ state_name  │  The most common state type
 │             │  Entry/exit actions listed inside
 └─────────────┘

 ╔═════════════╗  Compound State (rounded rectangle, double border)
 ║ parent_name ║  Contains child states
 ║ ┌────┐┌────┐║  Children rendered inside the parent boundary
 ║ │ a  ││ b  │║
 ║ └────┘└────┘║
 ╚═════════════╝

 ┌╌╌╌╌╌╌╌╌╌╌╌╌╌┐  Parallel State (rounded rectangle, dashed border)
 ╎ region_a     ╎  Regions separated by dashed horizontal line
 ╎╌╌╌╌╌╌╌╌╌╌╌╌╌╎  All regions active simultaneously
 ╎ region_b     ╎
 └╌╌╌╌╌╌╌╌╌╌╌╌╌┘

 ◉               Final State (bullseye - circle within circle)
                  Optional result class name shown nearby
```

### Transition Types

```
 ───EVENT_NAME──────>  Normal transition (solid arrow)
                       Event name as label

 ───EVENT_NAME──────>  Guarded transition (solid arrow)
     [isValidGuard]    Guard in square brackets below event

 ───EVENT_NAME──────>  Full pipeline (solid arrow)
  ⚙ totalCalc          Calculator (gear icon, runs first)
  [isValidGuard]       Guard (brackets, runs second)
  / createOrderAction  Action (slash prefix, runs on success)

 ╶╶╶╶╶╶╶╶╶╶╶╶╶╶╶╶╶>  Always-transition (dashed arrow)
                       Fires automatically on state entry

 ───EVENT_NAME───┬──>  Multi-branch (forking arrow)
  [guardA]       │     Multiple branches with different guards
                 └──>  Fallback branch (no guard)
  [else]
```

### State Interior

```
 ┌──────────────────────┐
 │ awaiting_payment      │  State name (snake_case)
 │                       │
 │ entry / logAction     │  Entry actions (with / prefix)
 │ entry / notifyAction  │  Multiple entry actions listed
 │ exit  / cleanupAction │  Exit actions
 │                       │
 │ meta: { color: blue } │  Meta data (collapsed by default)
 └───────────────────────┘
```

### Color Semantics

| Color | Meaning |
|-------|---------|
| Blue (default) | Normal state |
| Green | Initial state highlight |
| Red border | Analysis warning (unreachable, deadlock) |
| Yellow border | Analysis notice (missing guard fallback) |
| Gray / dimmed | Inactive in simulation mode |
| Bright / highlighted | Active in simulation mode |
| Purple | Parallel region indicator |

---

## Design Modes

The editor supports three modes, inspired by the research findings.
Users can switch between modes freely.

### Mode 1: Sketch (Sketch.systems philosophy)

> "What is the shape of this behavior?"

Purpose: Early exploration. Think about states and events without
implementation details.

**Available:**
- Add/remove/rename states
- Add/remove transitions with event names
- Set initial state
- Create compound/parallel structure
- Auto-layout

**Hidden:**
- Guards, actions, calculators (not shown, not editable)
- Context schema
- Event payloads
- Behavior wiring

**Why:** Sketch.systems proved that constraining the design phase
to just states and events helps users focus on the "shape" of behavior
before getting lost in implementation details. This follows Horrocks'
methodology steps 1-3: identify modes, sub-modes, and events.

### Mode 2: Design (Stately Studio-like)

> "What are the rules and side effects?"

Purpose: Full machine specification. Define every guard, action,
calculator, event payload, and context key.

**Available (in addition to Sketch):**
- Inspector panel for state/transition properties
- Context schema editor
- Event payload designer (field names, types, validation rules)
- Transition pipeline editor (calculator -> guard -> action ordering)
- Behavior wiring (inline reference vs class FQCN)
- Entry/exit action configuration
- Meta data editor
- @always transition configuration

**Visual additions:**
- Guards shown on transition arrows
- Actions shown on transition arrows and inside states
- Calculator badges on transitions
- Context panel showing the full schema

### Mode 3: Simulate (Stately + YAKINDU inspired)

> "What happens when I send this event?"

Purpose: Interactive exploration of machine behavior.
Walk through the machine, observe context changes, find edge cases.

**Available:**
- Click events to fire transitions
- Context evolution panel (shows before/after for each step)
- Path highlighting (show which states were visited)
- Rewind/replay (step backward through history)
- "What if" branching (try different event sequences)

**Analysis overlay:**
- Reachability indicators
- Deadlock warnings
- Guard coverage gaps
- Event coverage matrix
- Unused context keys

---

## JSON Intermediate Format

The canonical format for machine definitions in the editor.
Separated into two sections: `machine` (logic) and `layout` (visual).

```json
{
  "$schema": "https://event-machine.dev/schema/v1.json",
  "version": "1.0",

  "machine": {
    "id": "order_workflow",
    "initial": "pending",
    "should_persist": true,

    "context": {
      "total_amount": { "type": "int", "default": 0 },
      "items_count": { "type": "int", "default": 0 },
      "customer_id": { "type": "int", "default": null, "nullable": true }
    },

    "events": {
      "SUBMIT_ORDER": {
        "payload": {
          "items": { "type": "array", "rules": ["required", "min:1"] },
          "address_id": { "type": "int", "rules": ["required", "exists:addresses,id"] }
        }
      },
      "CANCEL_ORDER": {
        "payload": {
          "reason": { "type": "string", "rules": ["nullable", "max:500"] }
        }
      }
    },

    "states": {
      "pending": {
        "type": "atomic",
        "description": "Order created, awaiting submission",
        "entry": ["logCreatedAction"],
        "on": {
          "SUBMIT_ORDER": {
            "target": "processing",
            "calculators": ["calculateTotalCalculator"],
            "guards": ["hasItemsGuard"],
            "actions": ["createOrderAction", "sendConfirmationAction"]
          },
          "CANCEL_ORDER": {
            "target": "cancelled"
          }
        }
      },
      "processing": {
        "type": "compound",
        "initial": "validating",
        "states": {
          "validating": {
            "type": "atomic",
            "on": {
              "@always": {
                "target": "approved",
                "guards": ["isPaymentValidGuard"]
              }
            }
          },
          "approved": {
            "type": "atomic",
            "on": {
              "SHIP": { "target": "shipped" }
            }
          },
          "shipped": {
            "type": "final"
          }
        },
        "@done": "fulfilled"
      },
      "fulfilled": {
        "type": "final",
        "result": "OrderResult"
      },
      "cancelled": {
        "type": "final"
      }
    },

    "behaviors": {
      "actions": {
        "logCreatedAction": { "type": "inline" },
        "createOrderAction": { "type": "class", "class": "App\\Actions\\CreateOrderAction" },
        "sendConfirmationAction": { "type": "class", "class": "App\\Actions\\SendConfirmationAction" }
      },
      "guards": {
        "hasItemsGuard": { "type": "class", "class": "App\\Guards\\HasItemsGuard" },
        "isPaymentValidGuard": { "type": "class", "class": "App\\Guards\\IsPaymentValidGuard" }
      },
      "calculators": {
        "calculateTotalCalculator": { "type": "class", "class": "App\\Calculators\\CalculateTotalCalculator" }
      },
      "results": {
        "OrderResult": { "type": "class", "class": "App\\Results\\OrderResult" }
      }
    }
  },

  "layout": {
    "direction": "LR",
    "states": {
      "pending": { "x": 100, "y": 200, "width": 220, "height": 100 },
      "processing": { "x": 420, "y": 100, "width": 400, "height": 300 },
      "processing.validating": { "x": 30, "y": 60, "width": 150, "height": 70 },
      "processing.approved": { "x": 210, "y": 60, "width": 150, "height": 70 },
      "processing.shipped": { "x": 210, "y": 170, "width": 150, "height": 70 },
      "fulfilled": { "x": 900, "y": 150, "width": 150, "height": 70 },
      "cancelled": { "x": 100, "y": 450, "width": 150, "height": 70 }
    },
    "edges": {},
    "viewport": { "x": 0, "y": 0, "zoom": 1 }
  },

  "meta": {
    "created_at": "2026-03-07T12:00:00Z",
    "updated_at": "2026-03-07T14:30:00Z",
    "designer_version": "0.1.0"
  }
}
```

---

## Phased Roadmap

### Phase 0: Bridge (PHP side)

Build the connection between EventMachine and the Designer.

**Deliverables:**
1. JSON Schema for EventMachine config format (`schema/machine.json`)
2. `php artisan machine:export-json {machine}` - Export machine definition as JSON
3. `php artisan machine:import-json {path}` - Import JSON and generate PHP machine class
4. `php artisan machine:export-schema` - Output the JSON schema itself
5. Round-trip test: export -> import -> export produces identical output

**Why first:** Everything else depends on a stable, well-defined JSON format.
This phase also forces us to formalize every aspect of the machine config.

### Phase 1: Canvas (Read-Only Visualization)

Render a machine definition as an interactive diagram.

**Deliverables:**
1. React + TypeScript project setup (Vite)
2. JSON file import (drag-and-drop or file picker)
3. React Flow integration with custom node components:
   - Atomic state node
   - Compound state node (with children)
   - Parallel state node (with regions)
   - Final state node
   - Initial pseudo-state
4. React Flow custom edge components:
   - Normal transition (solid arrow with event label)
   - Always-transition (dashed arrow)
   - Multi-branch transition (forking arrows)
5. ELK.js automatic layout (layered algorithm, handles nested structures)
6. Guard/action/calculator labels on edges
7. Entry/exit action labels inside state nodes
8. Minimap, zoom controls, fit-to-view
9. State type badges and color coding

**What it does NOT do yet:** No editing, no simulation, no AI.

### Phase 2: Sketch Mode

The minimal editing experience. States and events only.

**Deliverables:**
1. Add state: double-click canvas -> name input -> state appears
2. Remove state: select + delete key
3. Rename state: double-click state name
4. Add transition: drag from state handle -> drop on target state -> event name input
5. Remove transition: select edge + delete key
6. Set initial state: right-click context menu
7. Create compound state: drag a state into another, or right-click -> "Make compound"
8. Create parallel state: right-click -> "Make parallel" -> add regions
9. Move states: drag and drop (positions saved to layout)
10. Auto-layout button (re-run ELK.js)
11. Undo/redo (Ctrl+Z / Ctrl+Shift+Z)
12. Export to JSON (download file)

**Design principle:** No inspector panel, no properties. Everything happens
on the canvas. This is the "napkin sketch" experience.

### Phase 3: Design Mode

Full machine specification with inspector panel.

**Deliverables:**
1. Inspector panel (right sidebar):
   - State inspector: name, type, description, entry/exit actions, meta
   - Transition inspector: event, target, guards, calculators, actions, description
   - Machine inspector: id, initial, version, should_persist
2. Context schema editor:
   - Add/remove/edit context keys
   - Type selection (int, string, float, bool, array, nullable)
   - Default values
   - "Used by" indicator (which guards/actions/calculators reference this key)
3. Event type designer:
   - Define event types with payload fields
   - Field names, types, validation rules
   - Preview of the EventBehavior class that would be generated
4. Transition pipeline editor:
   - Visual pipeline: Calculator -> Guard -> Action
   - Drag to reorder actions within a step
   - Add/remove behaviors
5. Behavior wiring panel:
   - Choose "inline" or "class reference" for each behavior
   - Class FQCN input with auto-completion from imported class list
   - Parameter syntax support (`guardName:arg1,arg2`)
6. Monaco Editor panel (toggleable):
   - Shows live JSON representation
   - Edits in Monaco update the canvas (bidirectional)
   - Syntax highlighting and validation against JSON schema

### Phase 4: Simulator

Interactive machine exploration and static analysis.

**Deliverables:**
1. Simulate mode toggle (canvas becomes non-editable):
   - Current state highlighted (bright)
   - Available transitions shown as clickable buttons
   - Unavailable states dimmed
2. Event firing: click an available event -> transition animates -> new state highlighted
3. Context evolution panel:
   - Shows context state at each step
   - Diff view: what changed after each transition
   - Calculator effects highlighted
4. Path history:
   - Breadcrumb trail of states visited
   - Step backward/forward
   - Reset to initial
5. Static analysis dashboard:
   - Reachability analysis (unreachable states marked red)
   - Deadlock detection (non-final states with no exits marked red)
   - Determinism check (ambiguous transitions marked yellow)
   - Guard coverage (multi-branch without fallback marked yellow)
   - Event coverage matrix (states x events grid)
   - Context flow analysis (which transitions read/write which keys)
   - Unused context keys warning
6. Path explorer:
   - Select start/end state -> enumerate all possible paths
   - Highlight selected path on canvas
   - Show guard conditions that must be true for each path

### Phase 5: Tool Interface (AI-Ready)

Expose the editor as a set of tools that any AI can use.

**Deliverables:**
1. MCP Server implementation:
   - All read/write/analysis/generate tools from the Tool Interface section
   - WebSocket transport for real-time updates
   - HTTP transport for simple request/response
2. Tool schema publication:
   - JSON file describing all available tools, their parameters, and return types
   - Auto-generated from TypeScript types
3. Claude Code skill definition:
   - `.claude/skills/event-machine-designer.md` file
   - Describes how to connect to the running editor
   - Lists all available tools with usage examples
   - Provides design methodology guidance (Horrocks' steps)
4. REST API (alternative to MCP):
   - Same tools accessible via HTTP endpoints
   - OpenAPI/Swagger documentation
5. Event stream:
   - Real-time notifications when the machine changes
   - AI can "watch" the editor and react to changes

**How AI interaction works:**
```
User: "Bu makineye bir timeout akisi ekle"

Claude Code (using the skill):
1. designer.machine.get -> reads current machine
2. Understands the structure
3. designer.state.add("expired", type: "final")
4. designer.transition.add("processing", "expired", "TIMEOUT")
5. designer.analyze.all -> checks for issues
6. Reports back to user: "Added 'expired' state with TIMEOUT transition from 'processing'"
```

The editor updates the canvas in real-time as the AI makes changes.
The user sees the machine evolving visually while the AI works.

### Phase 6: Code Generation

Generate production-ready PHP code from the editor.

**Deliverables:**
1. PHP array config generation:
   - Complete `MachineDefinition::define()` call
   - Proper formatting (Laravel Pint compatible)
   - EventMachine naming conventions enforced
2. Behavior class stubs:
   - `ActionBehavior` subclass with correct signature
   - `GuardBehavior` subclass with correct signature
   - `CalculatorBehavior` subclass with correct signature
   - `EventBehavior` subclass with `getType()`, `rules()`, payload properties
   - `ResultBehavior` subclass
   - `ContextManager` subclass (for typed context)
3. Test scenario generation:
   - Pest test file with test cases for each path
   - Happy path tests
   - Guard rejection tests
   - Edge case tests (from static analysis findings)
4. Diagram exports:
   - PlantUML (enhanced - includes guards, exit actions, parallel states)
   - Mermaid stateDiagram-v2
   - SVG (from React Flow canvas)
   - PNG (rasterized SVG)

### Phase 7: Advanced Features

**Potential future additions (not planned in detail):**

1. **Version diff visualization**
   - Import two JSON files, show visual diff
   - Added states (green), removed (red), modified (yellow)

2. **Live machine monitoring**
   - Connect to Laravel app's database
   - Overlay: how many instances are in each state
   - Bottleneck detection, error rate per transition

3. **Collaborative editing**
   - Real-time multi-user editing (CRDT-based)
   - Comments on states/transitions
   - Review/approval workflow

4. **VS Code extension wrapper**
   - Embed the web app in a VS Code webview
   - Open `.machine.json` files directly
   - PHP file detection and auto-export

---

## Tech Stack

| Layer | Technology | Why |
|-------|-----------|-----|
| **Framework** | React 18+ | Ecosystem, React Flow requirement |
| **Language** | TypeScript (strict) | Type safety for complex state management |
| **Build** | Vite | Fast dev server, optimized builds |
| **Canvas** | React Flow (xyflow) | Sub-flow support, custom nodes/edges, 25k+ stars |
| **Layout** | ELK.js | Nested node layout (dagre can't do hierarchy) |
| **Code Editor** | Monaco Editor | VS Code engine, JSON/PHP syntax support |
| **State Management** | Zustand | Simple, performant, supports undo/redo middleware |
| **Styling** | Tailwind CSS | Utility-first, consistent with Laravel ecosystem |
| **MCP Server** | TypeScript MCP SDK | Official SDK for MCP protocol |
| **Testing** | Vitest + Playwright | Unit + E2E testing |
| **Docs** | VitePress | Same as EventMachine docs |

### Project Structure

```
event-machine-designer/
  src/
    components/
      canvas/           # React Flow canvas, custom nodes, custom edges
      inspector/         # Properties panel, context editor, event designer
      simulator/         # Simulation controls, path explorer
      toolbar/           # Mode switcher, actions, layout controls
    core/
      schema/            # JSON schema, validation, serialization
      machine/           # Machine store, operations, undo/redo
      layout/            # ELK.js layout engine integration
      analysis/          # Static analysis algorithms
      codegen/           # PHP config, stub, test generation
    mcp/
      server.ts          # MCP server implementation
      tools/             # Tool definitions (read, write, analysis, generate)
    types/
      machine.ts         # TypeScript types mirroring EventMachine's PHP types
      editor.ts          # Editor-specific types (layout, viewport, etc.)
  public/
    schema/
      machine.v1.json    # Published JSON schema
  tests/
    unit/                # Component and logic tests
    e2e/                 # Playwright end-to-end tests
```

---

## Research References

This plan is informed by the following research:

### Foundational Theory
- **Harel, D. (1987)** "Statecharts: A Visual Formalism for Complex Systems"
  - Key formula: statecharts = state-diagrams + depth + orthogonality + broadcast
  - [PDF](https://www.state-machine.com/doc/Harel87.pdf)

- **Moody, D.L. (2009)** "The Physics of Notations"
  - 9 principles for cognitively effective visual notations
  - [Semantic Scholar](https://www.semanticscholar.org/paper/bcd2c5379a34068040750a751e4fd2710d90c15c)

- **Scaife & Rogers (1996)** "External Cognition: How Do Graphical Representations Work?"
  - Computational offloading, re-representation, graphical constraining
  - [PDF](https://www.sussex.ac.uk/informatics/cogslib/reports/csrp/csrp335.pdf)

### Design Methodology
- **Horrocks, I.** "Constructing the User Interface with Statecharts"
  - Systematic decomposition: modes -> sub-modes -> events -> guards -> actions
- **Samek, M.** "Practical UML Statecharts in C/C++"
  - Lightweight framework approach, active object pattern
  - [state-machine.com](https://www.state-machine.com/psicc2)

### Tools Studied
- **Stately Studio** - Bidirectional code-diagram sync, AI generation
  - [stately.ai](https://stately.ai/docs/studio)
- **XState Visualizer** - Open source reference implementation (React + ELK.js)
  - [GitHub](https://github.com/statelyai/xstate-viz)
- **Sketch.systems** - Constraint-based design philosophy
  - [sketch.systems](https://sketch.systems/)
- **YAKINDU/itemis CREATE** - Model-driven simulation and code generation
  - [itemis.com](https://www.itemis.com/en/yakindu/state-machine)
- **AWS Step Functions Workflow Studio** - Bidirectional design/code, individual state testing
  - [AWS Docs](https://docs.aws.amazon.com/step-functions/latest/dg/workflow-studio.html)
- **state-machine-cat** - Text-first, CI/CD-friendly approach
  - [GitHub](https://github.com/sverweij/state-machine-cat)
- **Stateflow (MATLAB)** - Industry standard, simulation with animation
- **Sparx Enterprise Architect** - UML state machine with table view

### AI + State Machines
- **ProtocolGPT (2024)** - RAG + LLM for state machine inference, >90% precision
  - [arXiv:2405.00393](https://arxiv.org/abs/2405.00393)
- **StateFlow (2024)** - LLM task-solving as state machines
  - [arXiv:2403.11322](https://arxiv.org/html/2403.11322v1)
- **SMoT (2024)** - State Machine of Thought, structured LLM reasoning
  - [arXiv:2312.17445](https://arxiv.org/html/2312.17445v1)
- **Harel + LLM Hybrid (2025)** - "State machines provide rails, LLMs provide flexibility"
  - [ACL Anthology](https://aclanthology.org/2025.clasp-main.3/)
- **AFLOW (ICLR 2025)** - Automated agentic workflow discovery
  - [arXiv:2410.10762](https://arxiv.org/pdf/2410.10762)
- **Stately Agent** - Production framework for state-machine-powered LLM agents
  - [GitHub](https://github.com/statelyai/agent)
