# Introduction to State Machines

## What is a State Machine?

A **state machine** is a mathematical model of computation that describes the behavior of a system at any given time. The system can be in exactly one of a finite number of states, and it can change from one state to another in response to events.

### Real-World Examples

State machines are everywhere in the real world:

- **Traffic Lights**: Red → Green → Yellow → Red
- **ATM Machine**: Idle → Card Inserted → PIN Entry → Transaction → Card Ejected
- **User Authentication**: Logged Out → Logging In → Logged In → Logging Out
- **Order Processing**: Pending → Processing → Shipped → Delivered

## Why Use State Machines?

### 1. **Clarity and Predictability**
State machines make complex logic explicit and predictable. Instead of scattered conditional statements throughout your code, you have a clear visual representation of all possible states and transitions.

```php
// Without state machine - scattered logic
if ($user->status === 'pending' && $request->action === 'approve') {
    $user->status = 'active';
    $this->sendWelcomeEmail($user);
} elseif ($user->status === 'active' && $request->action === 'suspend') {
    $user->status = 'suspended';
    $this->logSuspension($user);
}
// ... more scattered conditions

// With state machine - explicit and organized
$userMachine = UserMachine::create([
    'initial' => 'pending',
    'states' => [
        'pending' => [
            'on' => [
                'APPROVE' => [
                    'target' => 'active',
                    'actions' => 'sendWelcomeEmail'
                ]
            ]
        ],
        'active' => [
            'on' => [
                'SUSPEND' => [
                    'target' => 'suspended',
                    'actions' => 'logSuspension'
                ]
            ]
        ]
    ]
]);
```

### 2. **Impossible States Prevention**
State machines prevent impossible states by design. You can't accidentally put your system into a state that shouldn't exist.

### 3. **Easy Testing**
Each state and transition can be tested independently, making your code more reliable.

### 4. **Visual Understanding**
State machines can be easily visualized, making them great for documentation and team communication.

## Types of State Machines

### 1. **Finite State Machines (FSM)**
The simplest type, where the system can be in one state at a time.

### 2. **Hierarchical State Machines**
States can contain sub-states, allowing for more complex behaviors while maintaining organization.

### 3. **Parallel State Machines**
Multiple state machines can run concurrently, each managing different aspects of the system.

### 4. **History States**
States that remember the last active sub-state when transitioning back.

## EventMachine's Approach

EventMachine brings the power of state machines to Laravel applications with:

- **XState-Inspired API**: Familiar syntax for developers coming from the JavaScript ecosystem
- **Laravel Integration**: Seamless integration with Eloquent models, jobs, and events
- **Database Persistence**: Automatic state persistence and event sourcing
- **Type Safety**: Full PHP type safety with strict typing
- **Rich Behavior System**: Actions, guards, events, and results for complex logic
- **Testing Support**: Built-in testing utilities and faking capabilities

### Core Philosophy

EventMachine follows these principles:

1. **Explicit over Implicit**: Every state and transition must be explicitly defined
2. **Events Drive Changes**: State changes only happen through well-defined events
3. **Pure Functions**: Actions and guards should be side-effect free when possible
4. **Immutable State**: State changes create new state instances rather than mutating existing ones
5. **Comprehensive Logging**: Every state change is logged for debugging and auditing

## Key Concepts Overview

- **Machine**: The runtime instance that executes state transitions
- **States**: The possible conditions your system can be in
- **Events**: Triggers that can cause state transitions
- **Transitions**: The rules for moving from one state to another
- **Actions**: Side effects that occur during transitions
- **Guards**: Conditions that must be met for transitions to occur
- **Context**: Data that travels with the machine through state changes

## When to Use State Machines

State machines are particularly useful for:

- **User workflows** (registration, onboarding, approval processes)
- **Order processing** (pending → processing → shipped → delivered)
- **Document management** (draft → review → approved → published)
- **Game logic** (menu → playing → paused → game over)
- **API integrations** (idle → requesting → processing → success/error)
- **Complex form wizards** with multiple steps and validations

## When NOT to Use State Machines

State machines might be overkill for:

- Simple CRUD operations
- Linear processes without branching logic
- One-time calculations or transformations
- Very simple boolean flags

## Next Steps

Now that you understand the fundamentals, let's dive deeper:

- [States and Transitions](./states-and-transitions.md)
- [Events and Actions](./events-and-actions.md)
- [Your First State Machine](../getting-started/first-machine.md)