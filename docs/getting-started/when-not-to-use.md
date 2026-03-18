# When Not to Use a State Machine

Not every status column needs a state machine.

Consider a blog post with three statuses: `draft`, `published`, and `archived`. The flow is linear — a post moves forward through these stages, one at a time, with no branching, no conditions, and no side effects. An enum and a `publish()` method on the model are perfectly fine for this.

EventMachine is a powerful tool, but powerful tools have a cost: configuration, persistence, event sourcing infrastructure. When the problem is simple, the solution should be too.

::: info
David Khourshid, the creator of [XState](https://stately.ai/docs/xstate) (the JavaScript library that inspired EventMachine), puts it well: _"You don't need a library for state machines."_ A simple `switch` statement or an enum with a few methods is already a state machine — just an informal one.
:::

## Three Tiers of State Management

State management isn't binary — it's a spectrum. Not every status column needs EventMachine, but not every status column should stay a bare enum forever either.

| Tier | Tool | Enforcement | History | Best For |
|------|------|-------------|---------|----------|
| 1 | Status enum + Eloquent | None | None | Linear progression, ≤4 states, informational status |
| 2 | [Spatie Model States](https://spatie.be/docs/laravel-model-states) | Transition rules | Optional | 4–10 states, transition validation, state-specific behavior |
| 3 | EventMachine | Full statechart | Built-in event sourcing | Branching, guards, timers, delegation, audit trail |

To see the difference in practice, here is the same invoice lifecycle (`draft → sent → paid`) at each tier.

### Tier 1: Enum + Eloquent

```php no_run
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Sent  = 'sent';
    case Paid  = 'paid';
}

class Invoice extends Model
{
    protected $casts = ['status' => InvoiceStatus::class];

    public function markAsSent(): void
    {
        if ($this->status !== InvoiceStatus::Draft) {
            throw new \LogicException('Only draft invoices can be sent.');
        }

        $this->update(['status' => InvoiceStatus::Sent]);
    }

    public function markAsPaid(): void
    {
        if ($this->status !== InvoiceStatus::Sent) {
            throw new \LogicException('Only sent invoices can be marked as paid.');
        }

        $this->update(['status' => InvoiceStatus::Paid]);
    }
}
```

Simple, readable, no dependencies. For a three-state linear flow, this is the right choice.

### Tier 2: Spatie Model States

```php no_run
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

abstract class InvoiceState extends State
{
    public static function config(): StateConfig
    {
        return parent::config()
            ->default(DraftState::class)
            ->allowTransition(DraftState::class, SentState::class)
            ->allowTransition(SentState::class, PaidState::class);
    }
}

// Usage:
$invoice->status->transitionTo(SentState::class);
```

Adds transition enforcement and state-specific behavior. Useful when you have more states or need polymorphic methods per state.

### Tier 3: EventMachine

```php no_run
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class InvoiceMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'invoice',
                'initial' => 'draft',
                'context' => ['invoice_id' => null],
                'states'  => [
                    'draft' => [
                        'on' => [
                            'SEND' => 'sent',
                        ],
                    ],
                    'sent' => [
                        'on' => [
                            'PAY' => 'paid',
                        ],
                    ],
                    'paid' => [
                        'type' => 'final',
                    ],
                ],
            ],
        );
    }
}
```

This works, but it's overkill for a three-state linear flow. You get event sourcing, persistence, and a full state graph — none of which this invoice needs. The Tier 1 enum does the same job in fewer lines with no infrastructure.

**Use the lowest tier that meets your needs.** Each tier adds power _and_ complexity.
