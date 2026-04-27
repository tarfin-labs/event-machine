# Comparison with Other Libraries

EventMachine is a full statechart implementation for Laravel — not just a state machine, but a complete actor-based orchestration engine with event sourcing, time-based events, child machine delegation, and cross-machine communication. Here's how it compares to alternatives across ecosystems.

## Quick Comparison

| Feature | EventMachine | XState | Temporal | Step Functions | Spring SM | Spatie |
|---------|-------------|--------|----------|----------------|-----------|--------|
| **Event Sourcing** | Built-in | Via adapter | Replay-based | No (90d history) | No | No |
| **Declarative Config** | Yes | Yes | No (code) | Yes (JSON/ASL) | Yes (XML/Java) | No |
| **Guards & Actions** | Yes | Yes | N/A | Choice states | Yes | Yes |
| **Hierarchical States** | Yes | Yes | N/A | No | Yes | No |
| **Parallel States** | Yes (dispatch) | Yes | N/A | Parallel state | Yes (regions) | No |
| **Child Delegation** | Sync + async | invoke | Activities | Task states | No | No |
| **Cross-Machine Messaging** | sendTo/dispatchTo | sendTo | Signals | No | No | No |
| **Time-Based Events** | after/every | after/every | Timers | Wait states | No | No |
| **Scheduled Events** | Cron via Scheduler | No | Schedules | EventBridge | No | No |
| **Infinite Loop Protection** | Yes (configurable) | Partial | N/A | No | No | N/A |
| **Persistence** | MySQL (built-in) | None | Server DB | AWS managed | Optional | Eloquent |
| **HTTP Endpoints** | Built-in routing | No | No | API Gateway | No | No |
| **Test Faking** | Machine::fake() | No | No | No | No | No |
| **Infrastructure** | Your Laravel app | Your app | Temporal Server | AWS Cloud | Spring app | Laravel |
| **License** | MIT | MIT | MIT | Proprietary | Commercial (4.x+) | MIT |
| **Language** | PHP/Laravel | TypeScript/JS | Multi-language | JSON (ASL) | Java/.NET | PHP |

## PHP Ecosystem

### Spatie Laravel Model States

[spatie/laravel-model-states](https://github.com/spatie/laravel-model-states) is the most popular choice with 4.6M+ installs. It combines the state pattern with state machines, representing each state as a class.

```php no_run
// Spatie: States are classes
class PendingState extends PaymentState
{
    public function color(): string
    {
        return 'orange';
    }
}

$payment->state->transitionTo(PaidState::class);
```

**Strengths:**
- Clean OOP approach with state classes
- Automatic query scopes
- Well-maintained by Spatie

**Limitations:**
- No event history — only stores current state
- No audit trail — can't answer "how did we get here?"
- No child machine delegation or orchestration
- No time-based automation
- Single state per attribute

**Best for:** Simple state management where history doesn't matter.

---

### Asantibanez Eloquent State Machines

[asantibanez/laravel-eloquent-state-machines](https://github.com/asantibanez/laravel-eloquent-state-machines) adds history tracking and responsible party recording.

```php no_run
// Asantibanez: Transition with metadata
$order->status()->transitionTo(
    'approved',
    ['comments' => 'All ready to go'],
    $responsible = auth()->user()
);

// Query history
$order->status()->was('pending'); // true
$order->status()->timesWas('rejected'); // 2
```

**Strengths:**
- Built-in history tracking
- Responsible party tracking
- Pending/scheduled transitions
- Custom properties on transitions

**Limitations:**
- No event sourcing — history is separate from state
- No hierarchical or parallel states
- No machine delegation
- History stored in separate polymorphic table

**Best for:** When you need audit trails but not event sourcing or orchestration.

---

### Symfony Workflow Component

[symfony/workflow](https://symfony.com/doc/current/components/workflow.html) provides powerful workflow and state machine capabilities, available in Laravel via [zerodahero/laravel-workflow](https://github.com/zerodahero/laravel-workflow).

```php no_run
// Symfony: Configuration-based
$definition = $definitionBuilder
    ->addPlaces(['draft', 'reviewed', 'published'])
    ->addTransition(new Transition('to_review', 'draft', 'reviewed'))
    ->addTransition(new Transition('publish', 'reviewed', 'published'))
    ->build();

$workflow->apply($article, 'to_review');
```

**Strengths:**
- Supports both workflows (multiple active states) and state machines
- Visual diagram generation via Symfony Profiler
- Battle-tested in enterprise Symfony projects

**Limitations:**
- No built-in persistence or event history
- No child machine delegation
- No time-based events
- Configuration-heavy
- Feels "ported" to Laravel, not native

**Best for:** Complex enterprise workflows in Symfony projects.

---

### EventMachine

EventMachine is a full statechart implementation that combines XState's design philosophy with Laravel-native event sourcing and an actor-based runtime.

```php ignore
// EventMachine: Declarative + Event Sourced + Orchestrating
MachineDefinition::define(
    config: [
        'initial' => 'draft',
        'context' => OrderContext::class,
        'states' => [
            'draft' => ['on' => ['SUBMIT' => 'review']],
            'review' => [
                'on' => [
                    'APPROVE' => [
                        'target' => 'processing',
                        'guards' => CanApproveGuard::class,
                    ],
                ],
            ],
            'processing' => [
                'machine' => PaymentMachine::class,
                'queue'   => 'payments',
                '@done'   => 'shipped',
                '@fail'   => 'payment_failed',
                '@timeout' => ['target' => 'payment_timed_out', 'after' => 300],
            ],
            'shipped' => [
                'on' => [
                    'DELIVERY_OVERDUE' => [
                        'target'  => 'escalated',
                        'after'   => Timer::days(14),
                    ],
                ],
            ],
            'shipped'         => ['type' => 'final'],
            'payment_failed'  => ['type' => 'final'],
            'escalated'       => ['type' => 'final'],
        ],
    ],
    behavior: [
        'guards'  => ['APPROVE' => CanApproveGuard::class],
        'actions' => ['APPROVE' => SendNotificationAction::class],
    ],
);
```

**What sets EventMachine apart:**

- **Event sourcing built-in** — every transition persisted, complete audit trail, rebuild state from any point
- **Machine delegation** — parent states can invoke child machines (sync or async via queue), with `@done`/`@fail`/`@timeout` lifecycle
- **Cross-machine communication** — `sendTo()`, `dispatchTo()`, `sendToParent()`, `dispatchToParent()`, `raise()`
- **Time-based events** — `after` (one-shot) and `every` (recurring) timers on transitions, processed by a configurable sweep command
- **Scheduled events** — cron-based batch operations via `MachineScheduler` with resolver pattern
- **Parallel states with real dispatch** — concurrent region execution via Laravel queue with DB-level locking and LWW conflict detection
- **Infinite loop protection** — configurable depth guard (default 100) prevents stack overflow from `@always` and `raise()` cycles
- **HTTP endpoints** — register RESTful routes for machines with `output` response filtering
- **Machine faking** — short-circuit child delegation in tests with output injection and invocation assertions
- **Archival/compression** — archive millions of completed workflows, restore any time
- **Laravel-native** — DI, Eloquent, Artisan, Horizon, Pest — everything works naturally

**Best for:** Production Laravel applications that need orchestration, compliance-ready history, time-based automation, and enterprise scalability.

## Ruby/Rails Ecosystem

### AASM

[AASM](https://github.com/aasm/aasm) (Acts As State Machine) is the most popular Ruby gem with extensive callback support.

```ruby
class Job
  include AASM

  aasm do
    state :sleeping, initial: true
    state :running, :cleaning

    event :run do
      transitions from: :sleeping, to: :running
    end
  end
end

job.run! # Transitions to running
```

**Strengths:**
- Simple DSL
- Extensive callbacks (before, after, around)
- Auto-generated scopes

**Limitations:**
- No event history — stores current state only
- No orchestration or delegation
- Can encourage complex callback chains

---

### Statesman

[Statesman](https://github.com/gocardless/statesman) by GoCardless prioritizes database-backed audit trails.

```ruby
class OrderStateMachine
  include Statesman::Machine

  state :pending, initial: true
  state :approved
  state :shipped

  transition from: :pending, to: :approved
  transition from: :approved, to: :shipped

  guard_transition(to: :shipped) do |order|
    order.ready_to_ship?
  end
end

order.transition_to!(:approved, metadata: { approved_by: 'admin' })
order.history # All transitions with timestamps and metadata
```

**Strengths:**
- Database-backed transition history
- Metadata on each transition
- Keeps logic separate from models

**EventMachine equivalent:** Similar audit-trail philosophy, but EventMachine adds event sourcing (rebuilding state from events), machine delegation, time-based automation, and archival.

## JavaScript Ecosystem

### XState

[XState](https://stately.ai/docs/xstate) is the gold standard for JavaScript state machines and the primary inspiration for EventMachine's design.

```typescript
import { createMachine, assign } from 'xstate';

const orderMachine = createMachine({
  id: 'order',
  initial: 'draft',
  context: { items: [], total: 0 },
  states: {
    draft: {
      on: { SUBMIT: 'review' }
    },
    review: {
      on: {
        APPROVE: {
          target: 'approved',
          guard: 'hasItems',
          actions: 'calculateTotal'
        }
      }
    },
    approved: { type: 'final' }
  }
});
```

**Strengths:**
- Full actor model with `invoke` for services and child machines
- Hierarchical and parallel states
- Visual editor ([Stately Studio](https://stately.ai))
- TypeScript-first
- Works frontend and backend

**Limitations for backend use:**
- No built-in persistence — event sourcing requires custom adapters
- No database integration
- No PHP/Laravel support

**EventMachine's relationship with XState:**

EventMachine started as "XState for Laravel" and has evolved into a comparable implementation for the server-side PHP ecosystem. The feature parity is now close:

| XState Concept | EventMachine Equivalent |
|----------------|------------------------|
| `createMachine()` | `MachineDefinition::define()` |
| `invoke` (services) | `machine` / `job` keys |
| `sendTo()` | `sendTo()` / `dispatchTo()` |
| `after` / `every` (delays) | `after` / `every` (Timer VO) |
| `assign()` | Actions + ContextManager |
| Actor model | Machine delegation + queue dispatch |
| Stately Studio | `machine:xstate` export → Stately Studio |

**What EventMachine adds beyond XState:**
- Built-in event sourcing and persistence
- Complete history with archival/compression
- Scheduled events (cron-based batch operations)
- HTTP endpoint routing
- Machine faking for tests
- Infinite loop protection with configurable depth
- Laravel-native DI, Eloquent, Artisan, Horizon

## Java / .NET / Cloud Ecosystem

### Spring Statemachine

[Spring Statemachine](https://spring.io/projects/spring-statemachine/) provides state machine capabilities for Spring applications with hierarchical states, regions, and guards.

**Strengths:**
- Hierarchical and parallel states (regions)
- Spring IoC integration
- Persistent state machine configurations

**Limitations:**
- **Open-source discontinued** — as of 2025, Spring Statemachine is no longer maintained as open-source. Version 4.0.x is the last public release; future versions are only available to Tanzu Spring commercial customers
- Known infinite loop bugs in GitHub issues (no built-in protection)
- No built-in event sourcing or history
- Complex XML/Java configuration

---

### Akka / Akka.NET

[Akka](https://doc.akka.io) is a full actor model implementation for JVM/.NET with event sourcing (Akka Persistence) and FSM support.

**Strengths:**
- True distributed actor model with clustering
- Event sourcing via Akka Persistence (event replay)
- Durable state as alternative to event sourcing
- Battle-tested at massive scale (LinkedIn, PayPal)

**Limitations:**
- Extremely complex — requires deep understanding of actor model, supervision, clustering
- BSL license change (Akka 2.7+) — no longer fully open-source
- No declarative state machine DSL — FSMs are coded imperatively via behaviors
- JVM/.NET only

**How EventMachine compares:** EventMachine brings Akka-level concepts (actors, event sourcing, child delegation, messaging) to PHP/Laravel with a declarative DSL. You don't need to understand supervision trees or cluster sharding — the machine definition handles orchestration.

---

### Temporal

[Temporal](https://temporal.io) is a durable execution engine for orchestrating microservices and long-running workflows.

**Strengths:**
- Durable execution — workflows survive process crashes via event history replay
- Language-agnostic (Go, Java, TypeScript, Python, .NET SDKs)
- Built for distributed systems at scale
- Automatic retries, timeouts, and signals

**Limitations:**
- Requires separate Temporal Server infrastructure (self-hosted or Temporal Cloud)
- Workflows are code, not declarative state machines — no visual state diagram
- No built-in state machine abstraction (state must be modeled manually)
- Steep operational complexity

**How EventMachine compares:** Temporal is an infrastructure platform; EventMachine is a library. Temporal requires its own server cluster, separate SDKs, and operational expertise. EventMachine runs inside your existing Laravel app with MySQL and Redis you already have. For teams that need workflow orchestration without infrastructure overhead, EventMachine provides machine delegation, timers, and scheduled events within Laravel's ecosystem.

---

### AWS Step Functions

[AWS Step Functions](https://docs.aws.amazon.com/step-functions/latest/dg/welcome.html) is a serverless orchestration service that uses the Amazon States Language (ASL) to define state machines.

**Strengths:**
- Fully managed — no infrastructure to maintain
- Visual workflow designer in AWS Console
- Integrates with 200+ AWS services
- Standard workflows for long-running (up to 1 year), Express for high-volume

**Limitations:**
- AWS vendor lock-in — ASL is proprietary
- JSON-based configuration, not code — limited expressiveness
- No event sourcing — execution history is ephemeral (90 days retention)
- Pricing per state transition (can be expensive at scale)
- No local development story without LocalStack

**How EventMachine compares:** Step Functions is cloud-first infrastructure; EventMachine is framework-first code. EventMachine gives you state machines inside your Laravel application with permanent event sourcing history, while Step Functions requires AWS and stores execution history temporarily. For Laravel teams, EventMachine avoids cloud vendor lock-in and keeps orchestration logic in your codebase.

---

### Microsoft Orleans

[Orleans](https://learn.microsoft.com/en-us/dotnet/orleans/overview) is a virtual actor framework for .NET that inspired much of the modern actor model thinking.

**Strengths:**
- Virtual actors (grains) — automatic lifecycle management, location transparency
- Persistent state with multiple named storage objects
- Scales to millions of concurrent actors
- Automatic partitioning and failover

**Limitations:**
- .NET only
- No built-in state machine abstraction — actors must implement FSM logic manually
- Steep learning curve for grain lifecycle and persistence patterns

**How EventMachine compares:** Orleans pioneered the virtual actor concept that influenced XState and, transitively, EventMachine. EventMachine provides the state machine abstraction that Orleans lacks — you define states and transitions declaratively instead of coding actor behavior imperatively.

## When to Choose EventMachine

Choose **EventMachine** when you need:

- **Orchestration** — machines that launch child machines, communicate across instances, and react to time
- **Compliance-ready audit trails** — every transition recorded with full context, restorable from any point
- **Time-based automation** — expiration timers, recurring billing, retry escalation chains
- **Enterprise scale** — archive millions of completed workflows, parallel dispatch with DB-level locking
- **Laravel-native everything** — DI, Eloquent, Artisan, Horizon, Pest — no adapters needed

Choose **Spatie Model States** when:
- You need simple state tracking on Eloquent models
- History doesn't matter
- You want minimal setup

Choose **Symfony Workflow** when:
- You're in the Symfony ecosystem
- You need visual workflow diagrams from the Symfony Profiler

Choose **XState** when:
- You're building frontend state logic (React, Vue, Svelte)
- You want the Stately Studio visual editor for design

Choose **Temporal** when:
- You're orchestrating distributed microservices across languages
- You need durable execution with crash recovery at infrastructure level
- You have the operational capacity to run Temporal Server

Choose **AWS Step Functions** when:
- You're already on AWS and want fully managed orchestration
- You need integration with 200+ AWS services
- Vendor lock-in is acceptable for your use case

::: tip Use both
EventMachine's `machine:xstate` command exports your machine definition to XState v5 JSON format. You can design in [Stately Studio](https://stately.ai), then implement in EventMachine — or export your EventMachine definitions for frontend visualization.
:::

## Sources

### PHP
- [Spatie Laravel Model States](https://spatie.be/docs/laravel-model-states/v2/01-introduction)
- [Asantibanez Eloquent State Machines](https://github.com/asantibanez/laravel-eloquent-state-machines)
- [Symfony Workflow Component](https://symfony.com/doc/current/components/workflow.html)
- [zerodahero/laravel-workflow](https://github.com/zerodahero/laravel-workflow)

### Ruby
- [AASM](https://github.com/aasm/aasm)
- [Statesman](https://dev.to/daviducolo/a-deep-dive-into-the-statesman-gem-for-ruby-building-flexible-state-machines-5b83)
- [State Machines in Ruby](https://blog.appsignal.com/2022/06/22/state-machines-in-ruby-an-introduction.html)

### JavaScript
- [XState Documentation](https://stately.ai/docs/xstate)

::: tip Testing
See [Testing Overview](/testing/overview) for EventMachine's testing capabilities.
:::
