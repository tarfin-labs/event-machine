<div align="center">

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="./art/event-machine-logo-dark.svg">
  <img alt="EventMachine" src="./art/event-machine-logo-light.svg" height="300">
</picture>

[![Latest Version on Packagist](https://img.shields.io/packagist/v/tarfin-labs/event-machine.svg?style=flat-square)](https://packagist.org/packages/tarfin-labs/event-machine)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/tarfin-labs/event-machine/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/tarfin-labs/event-machine/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/tarfin-labs/event-machine.svg?style=flat-square)](https://packagist.org/packages/tarfin-labs/event-machine)

**Event-driven state machines for Laravel**

[Documentation](https://event-machine.tarfin.com) · [Installation](#installation) · [Why EventMachine?](#why-eventmachine)

</div>

---

## Why EventMachine?

**Your business logic deserves better than nested if-statements.**

EventMachine brings the power of finite state machines to Laravel, inspired by [XState](https://xstate.js.org). Define your states, transitions, and behaviors declaratively - and let the machine handle the complexity.

### The Problem

```php
// Without state machines: scattered conditionals, hidden rules, impossible to test
if ($order->status === 'pending' && $user->can('approve') && !$order->isExpired()) {
    if ($order->total > 10000 && !$order->hasSecondApproval()) {
        // More nested logic...
    }
}
```

### The Solution

```php
// With EventMachine: clear states, explicit transitions, testable behaviors
MachineDefinition::define(
    config: [
        'initial' => 'pending',
        'states' => [
            'pending' => [
                'on' => [
                    'APPROVE' => [
                        'target' => 'approved',
                        'guards' => [CanApproveGuard::class, NotExpiredGuard::class],
                    ],
                ],
            ],
            'approved' => [
                'entry' => NotifyCustomerAction::class,
            ],
        ],
    ],
);
```

### Key Benefits

| Feature | Description |
|---------|-------------|
| **Event Sourced** | Every transition persisted. Full audit trail. Replay history. |
| **Behaviors** | Guards validate, calculators compute, actions execute. |
| **Testable** | Fake any behavior. Assert states. Verify transitions. |
| **Type-Safe Context** | Spatie Data powered. Validated. IDE autocompletion. |
| **Archival** | Compress millions of events. Restore any machine instantly. |
| **Laravel Native** | Eloquent, DI, Artisan commands. Built for Laravel. |

## Installation

```bash
composer require tarfin-labs/event-machine
```

```bash
php artisan vendor:publish --tag="event-machine-migrations"
php artisan migrate
```

## Eloquent Integration

```php
class Order extends Model
{
    use HasMachines;

    protected $casts = [
        'machine' => MachineCast::class.':'.OrderMachine::class,
    ];
}

// Use it naturally
$order = Order::create();
$order->machine->send(['type' => 'SUBMIT']);
$order->machine->send(['type' => 'APPROVE']);

$order->machine->state->matches('approved'); // true
$order->machine->state->history->count();    // 3 events tracked
```

## Documentation

For guards, actions, calculators, hierarchical states, validation, testing, and more:

**[Read the Documentation →](https://event-machine.tarfin.com)**

## Credits

- [Yunus Emre Deligöz](https://github.com/deligoez)
- [Fatih Aydın](https://github.com/aydinfatih)
- [Yunus Emre Nalbant](https://github.com/YunusEmreNalbant)
- [Faruk Can](https://github.com/frkcn)
- [Turan Karatuğ](https://github.com/tkaratug)
- [Yılmaz Demir](https://github.com/yidemir)
- Maybe you? [Contribute →](../../contributing)

## License

MIT License. See [LICENSE](LICENSE.md) for details.
