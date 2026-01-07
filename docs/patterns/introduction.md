# Pattern Examples

Real-world patterns and complete examples to learn from.

Each pattern demonstrates a different aspect of EventMachine:

| Pattern | Demonstrates |
|---------|--------------|
| [Traffic Light](/patterns/traffic-light) | Custom context classes, typed behaviors, validation guards |
| [Order Processing](/patterns/order-processing) | Multi-step workflows, hierarchical states, result behaviors |
| [Calculator](/patterns/calculator) | Event payloads, state machine operations, context updates |
| [Elevator](/patterns/elevator) | Complex state logic, queued events, multi-path transitions |
| [Guarded Transitions](/patterns/guarded-transitions) | Guard patterns, multi-branch transitions, conditional logic |

## How to Use These Examples

1. **Start with Traffic Light** - Simple example showing all core concepts
2. **Move to Order Processing** - Real business workflow pattern
3. **Study Guarded Transitions** - Understand conditional flow control
4. **Explore Calculator & Elevator** - More complex state logic

## Pattern Structure

Each pattern includes:

- **Overview** - What the pattern demonstrates
- **State diagram** - Visual representation
- **Full code** - Complete, runnable implementation
- **Usage examples** - How to use the machine
- **Tests** - How to test the pattern
- **Key concepts** - What you learned

## Creating Your Own Patterns

Use these patterns as templates:

```php
// 1. Define your context
class MyContext extends ContextManager { ... }

// 2. Define events, guards, actions
class MyEvent extends EventBehavior { ... }
class MyGuard extends GuardBehavior { ... }
class MyAction extends ActionBehavior { ... }

// 3. Build the machine
class MyMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [...],
            behavior: [...],
        );
    }
}
```

## Next Steps

- [Traffic Light](/patterns/traffic-light) - Start here
- [Testing](/testing/introduction) - Learn to test your patterns
- [Laravel Integration](/laravel/introduction) - Use patterns in Laravel apps
