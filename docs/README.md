# EventMachine Documentation

EventMachine is a Laravel package for creating event-driven state machines, heavily influenced by XState. It provides an expressive language to define and manage application states, enabling developers to create complex workflows with ease and maintainability.

## Documentation Structure

### 🚀 [Getting Started](./getting-started/)
- [Installation](./getting-started/installation.md)
- [Quick Start](./getting-started/quick-start.md)
- [Your First State Machine](./getting-started/first-machine.md)

### 🧠 [Core Concepts](./concepts/)
- [Introduction to State Machines](./concepts/introduction.md)
- [States and Transitions](./concepts/states-and-transitions.md)
- [Events and Actions](./concepts/events-and-actions.md)
- [Context Management](./concepts/context.md)
- [Guards and Conditions](./concepts/guards.md)
- [Hierarchical States](./concepts/hierarchical-states.md)

### 📖 [Guides](./guides/)
- [Dependency Injection](./guides/dependency-injection.md)
- [Machine Definition](./guides/machine-definition.md)
- [Behavior System](./guides/behavior-system.md)
- [Database Integration](./guides/database-integration.md)
- [Laravel Integration](./guides/laravel-integration.md)
- [Error Handling](./guides/error-handling.md)
- [Performance Optimization](./guides/performance.md)

### 🔧 [Advanced Features](./advanced/)
- [Data Compression](./advanced/compression.md)
- [UML Diagram Generation](./advanced/visualization.md)
- [Eloquent Integration](./advanced/eloquent-integration.md)

### 📚 [API Reference](./api-reference/)
- [Machine Classes](./api-reference/machine-classes.md)
- [Definition Classes](./api-reference/definition-classes.md)
- [Behavior Classes](./api-reference/behavior-classes.md)
- [Exceptions](./api-reference/exceptions.md)
- [Configuration](./api-reference/configuration.md)

### 💡 [Examples](./examples/)
- [Calculator Machine](./examples/calculator.md)
- [Traffic Lights](./examples/traffic-lights.md)
- [Order Processing](./examples/order-processing.md)
- [User Authentication Flow](./examples/authentication.md)
- [Multi-Step Forms](./examples/multi-step-forms.md)

### 🧪 [Testing](./testing/)
- [Testing Strategies](./testing/strategies.md)
- [Faking and Mocking Behaviors](./testing/faking-behaviors.md)
- [Production Testing Patterns](./testing/production-patterns.md)
- [Unit Testing](./testing/unit-testing.md)
- [Integration Testing](./testing/integration-testing.md)
- [Mocking and Faking](./testing/mocking.md)

### 🔄 [Migration](./migration/)
- [Upgrading to v3.0](./migration/v3-upgrade.md)
- [Breaking Changes](./migration/breaking-changes.md)
- [Migration Tools](./migration/tools.md)

## Philosophy

EventMachine follows the philosophy of making complex application logic manageable through:

- **Explicit State Management**: Every possible state is clearly defined
- **Event-Driven Architecture**: State changes happen through well-defined events
- **Predictable Behavior**: The same input always produces the same output
- **Visual Representation**: State machines can be easily visualized and understood
- **Testability**: Every state and transition can be independently tested

## Contributing

This documentation is part of the EventMachine project. Contributions are welcome!

## Support

- 📧 Report issues at [GitHub Issues](https://github.com/tarfin-labs/event-machine/issues)
- 💬 Join discussions at [GitHub Discussions](https://github.com/tarfin-labs/event-machine/discussions)