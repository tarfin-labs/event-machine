# Comparison with Other Libraries

EventMachine isn't the only state machine library out there. Here's how it compares to alternatives in PHP, Ruby, and JavaScript ecosystems.

## Quick Comparison

| Feature | EventMachine | Spatie Model States | Asantibanez | Symfony Workflow | XState |
|---------|-------------|---------------------|-------------|------------------|--------|
| **Event Sourcing** | Built-in | No | No | No | Via adapter |
| **Complete History** | Yes | No | Yes | Via events | Via adapter |
| **Archival/Restore** | Yes | No | No | No | No |
| **Type-safe Context** | Spatie Data | No | No | No | TypeScript |
| **Guards** | Yes | Yes | Yes | Yes | Yes |
| **Actions** | Yes | Yes | Yes | Yes | Yes |
| **Hierarchical States** | Yes | No | No | Yes | Yes |
| **Multiple Fields** | Yes | No | Yes | Yes | Yes |
| **Laravel Native** | Yes | Yes | Yes | Adapter | No |

## PHP Ecosystem

### Spatie Laravel Model States

[spatie/laravel-model-states](https://github.com/spatie/laravel-model-states) is the most popular choice with 4.6M+ installs. It combines the state pattern with state machines, representing each state as a class.

```php
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
- No event history - only stores current state
- No audit trail - can't answer "how did we get here?"
- No archival for completed records
- Single state per attribute

**Best for:** Simple state management where history doesn't matter.

---

### Asantibanez Eloquent State Machines

[asantibanez/laravel-eloquent-state-machines](https://github.com/asantibanez/laravel-eloquent-state-machines) adds history tracking and responsible party recording.

```php
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
- No event sourcing - history is separate from state
- No archival for completed records
- No type-safe context
- History stored in separate polymorphic table

**Best for:** When you need audit trails but not event sourcing.

---

### Symfony Workflow Component

[symfony/workflow](https://symfony.com/doc/current/components/workflow.html) provides powerful workflow and state machine capabilities, available in Laravel via [zerodahero/laravel-workflow](https://github.com/zerodahero/laravel-workflow).

```php
// Symfony: Configuration-based
$definition = $definitionBuilder
    ->addPlaces(['draft', 'reviewed', 'published'])
    ->addTransition(new Transition('to_review', 'draft', 'reviewed'))
    ->addTransition(new Transition('publish', 'reviewed', 'published'))
    ->build();

$workflow->apply($article, 'to_review');
```

**Strengths:**
- Supports both workflows (multiple states) and state machines
- Visual diagram generation
- Powerful for complex business processes
- Battle-tested in enterprise

**Limitations:**
- No built-in persistence
- No event history without custom implementation
- Configuration-heavy
- Feels "ported" to Laravel, not native

**Best for:** Complex enterprise workflows, especially in Symfony projects.

---

### EventMachine

EventMachine combines the best of all worlds: XState-inspired design, event sourcing, and Laravel-native integration.

```php
// EventMachine: Declarative + Event Sourced
MachineDefinition::define(
    config: [
        'initial' => 'draft',
        'context' => OrderContext::class,
        'states' => [
            'draft'    => ['on' => ['SUBMIT' => 'review']],
            'review'   => ['on' => ['APPROVE' => 'approved']],
            'approved' => ['type' => 'final'],
        ],
    ],
    behavior: [
        'guards'  => ['APPROVE' => CanApproveGuard::class],
        'actions' => ['APPROVE' => SendNotificationAction::class],
    ]
);

// Every transition is persisted
$order->send(['type' => 'APPROVE', 'by' => 'admin']);

// Query complete history
$order->state->history; // All events from @init to now

// Archive millions of completed records
php artisan machine:archive-events

// Restore any machine, any time
$archived = OrderMachine::create(state: $rootEventId);
```

**Strengths:**
- Event sourcing built-in - complete audit trail
- Archival/compression for enterprise scale
- Type-safe context with Spatie Data
- Laravel-native DI, Eloquent, Artisan
- XState-inspired hierarchical states
- Testable behaviors (guards, actions, calculators)

**Best for:** Production Laravel applications that need compliance-ready history, scalability, and enterprise features.

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
- Multiple state machines per model

**Limitations:**
- No event history - stores current state only
- Heavy when included in ActiveRecord
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
- Queryable audit trail

**EventMachine equivalent:** Similar philosophy to EventMachine, but EventMachine adds event sourcing (rebuilding state from events) and archival.

---

### state_machines

[state_machines](https://github.com/state-machines/state_machines) provides a complete state machine implementation with ORM integrations.

```ruby
class Vehicle
  state_machine :state, initial: :parked do
    event :ignite do
      transition parked: :idling
    end

    after_transition on: :ignite, do: :put_on_seatbelt
  end
end
```

**Strengths:**
- Automatic scopes
- Transaction-safe transitions
- Multiple state machines
- Good ORM integration

## JavaScript Ecosystem

### XState

[XState](https://stately.ai/docs/xstate) is the gold standard for JavaScript state machines, and the primary inspiration for EventMachine's design.

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
- Actor model for complex orchestration
- Hierarchical and parallel states
- Visual editor (Stately)
- TypeScript-first
- Works frontend and backend

**Limitations for backend:**
- No built-in persistence
- Event sourcing requires custom adapters
- No Laravel/PHP integration

**EventMachine takes from XState:**
- Declarative configuration syntax
- Guards and actions
- Hierarchical states concept
- Context for extended state
- Event-driven transitions

**EventMachine adds:**
- Built-in event sourcing persistence
- Complete history with query support
- Archival/compression for scale
- Laravel-native integration
- Spatie Data for type-safe context

## When to Choose EventMachine

Choose **EventMachine** when you need:

- **Compliance-ready audit trails** - Every transition is recorded with full context
- **Event sourcing** - Rebuild state from events, not just store current state
- **Enterprise scale** - Archive millions of completed records, restore any time
- **Laravel-native** - DI, Eloquent, Artisan, Pest - everything works naturally
- **Type safety** - Spatie Data integration for validated, typed context

Choose **Spatie Model States** when:
- You only need simple state tracking
- History doesn't matter
- You want minimal setup

Choose **Symfony Workflow** when:
- You need visual workflow diagrams
- Objects can be in multiple states simultaneously
- You're already in the Symfony ecosystem

Choose **Asantibanez** when:
- You need history but not event sourcing
- Responsible party tracking is important
- You want scheduled/pending transitions

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
- [XState Event Sourcing](https://github.com/x-aaron-moore/xstate-event-sourcing-interpreter)
