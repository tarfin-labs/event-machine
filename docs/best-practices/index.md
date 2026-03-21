# Best Practices

Designing a state machine is different from writing procedural code. In a statechart, you model _conditions_ and _transitions_ rather than step-by-step instructions. These best practice guides distil lessons learned from real-world EventMachine projects into focused, actionable advice.

## Quick Reference

| Topic | One-Liner |
|-------|-----------|
| [Naming & Style](/building/conventions) | Consistent naming for states, events, behaviors, and context |
| [State Design](./state-design) | Model conditions, not steps; avoid state explosion |
| [Event Design](./event-design) | Events are past-tense facts, not commands |
| [Transition Design](./transition-design) | Self vs targetless, `@always` chains, multi-branch |
| [Guard Design](./guard-design) | Guards must be pure -- no side effects, no I/O |
| [Action Design](./action-design) | Idempotency, entry vs transition vs exit |
| [Context Design](./context-design) | Lean context; flags that change transitions belong in states |
| [Event Bubbling](./event-bubbling) | Understand leaf-to-root handler resolution |
| [Machine Decomposition](./machine-decomposition) | When to split, when to keep together |
| [Machine System Design](./machine-system-design) | Communication patterns, hierarchy, timer placement |
| [Time-Based Patterns](./time-based-patterns) | `after`, `every`, escalation, idempotency |
| [Parallel Patterns](./parallel-patterns) | Region independence, context separation, `@done` |
| [Testing Strategy](./testing-strategy) | Four layers: unit, integration, E2E, LocalQA |

## How to Read These Guides

Each page follows the same structure:

1. **Core principle** -- the one rule that matters most
2. **Do / Don't** examples -- always with code
3. **Refactoring recipe** -- turning an anti-pattern into a clean design
4. **Cross-references** -- links to the relevant reference documentation

The tone is practical. "Generally" means "in most projects we have seen". If your domain calls for something different, trust your domain -- but understand the trade-off.

## Background Reading

EventMachine's design draws heavily from statechart theory and existing implementations:

- **David Harel, "Statecharts: A Visual Formalism for Complex Systems" (1987)** -- the foundational paper introducing hierarchical and parallel states.
- **W3C SCXML Specification** -- the XML-based statechart standard that formalises event processing, transition selection, and document order.
- **XState (Stately)** -- the JavaScript statechart library that popularised statecharts in modern application development. EventMachine follows many of the same semantics while using its own PHP-native API.
- **UML State Machine Diagrams** -- the OMG standard for modelling reactive systems with states, transitions, and regions.

Understanding these foundations helps you reason about _why_ EventMachine works the way it does, not just _how_.
