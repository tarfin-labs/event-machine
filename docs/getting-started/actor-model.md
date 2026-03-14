# The Actor Model

EventMachine is an actor system. Each machine instance is an **actor** — an independent entity with encapsulated state that communicates exclusively through message passing.

## What Makes EventMachine an Actor System?

The [Actor Model](https://en.wikipedia.org/wiki/Actor_model), introduced by Carl Hewitt in 1973, defines actors as the fundamental unit of computation. Every actor can:

1. **Receive messages** → EventMachine machines receive events via `send()`
2. **Change internal state** → State transitions and context mutations
3. **Send messages to other actors** → `sendTo()`, `dispatchTo()`, `raise()`
4. **Create new actors** → `machine` and `job` keys create child actors

EventMachine maps these principles to a Laravel-native, database-backed architecture.

## Actor Model Mapping

| Actor Model Principle | EventMachine Equivalent |
|----------------------|------------------------|
| Encapsulated State | `ContextManager` — private, only accessible within the machine |
| Message Passing | `send()`, `sendTo()`, `dispatchTo()`, `raise()` |
| No Shared State | Each machine has its own context, isolated from others |
| Create New Actors | `machine` key (child machines), `job` key (job actors) |
| Behavior for Next Message | State transitions determine which events are accepted |
| Actor Identity | `machineClass` + `rootEventId` |
| Actor Persistence | `machine_events` table |
| Actor Hierarchy | Parent-child via `machine`/`job` key delegation |

## Virtual Actor Model

EventMachine follows the **Virtual Actor** pattern, similar to [Microsoft Orleans](https://learn.microsoft.com/en-us/dotnet/orleans/overview):

- **Database-backed:** Machines are persisted in the database. They "exist" conceptually even when not loaded in memory.
- **Identity-based:** Any machine can be addressed by its class + root event ID, from anywhere in the application.
- **Always-available:** `sendTo()` can reach any machine instance — restore it, deliver the event, persist the result.
- **Auto-persistence:** Every transition is automatically written to the database.

| Orleans Concept | EventMachine Equivalent |
|----------------|------------------------|
| Grain | Machine instance |
| Grain Identity (type + key) | `machineClass` + `rootEventId` |
| Grain State | `ContextManager` |
| Grain Activation | `Machine::create(state: $rootEventId)` (restore) |
| Grain-to-Grain call | `sendTo()` / `dispatchTo()` |
| Grain Persistence | `machine_events` table |

## Coming From Other Actor Systems

If you've used other actor frameworks, here's how EventMachine maps:

| Your Framework | Your Concept | EventMachine |
|---------------|-------------|--------------|
| **Akka** | `tell` (fire-and-forget) | `sendTo()` / `dispatchTo()` |
| **Akka** | `ask` (request-response) | Not available — use `@done` |
| **Akka** | Supervision (restart/stop) | `@fail` / `@timeout` transitions |
| **Elixir** | `GenServer.cast` | `dispatchTo()` |
| **Elixir** | `GenServer.call` | `sendTo()` (sync, but no response) |
| **Elixir** | `handle_info` | `on` map events |
| **Elixir** | Supervision tree | Parent-child via `machine` key |
| **XState** | `invoke` | `machine` key |
| **XState** | `spawn` | Not available — use `dispatchTo()` + `Machine::create()` |
| **XState** | `sendTo(actorRef)` | `sendTo(machineClass, rootEventId)` |
| **XState** | `fromPromise` | `job` key |

## What EventMachine Is NOT

- **Not an in-memory actor system** — State is database-backed, not in process memory
- **Not a long-running process** — PHP runs request-response, not persistent actors
- **Not a distributed system** — Single Laravel application (use Horizon for queue workers)
- **Not hot-reloadable** — PHP reloads on every request by nature
- **No request-response pattern** — Messages are one-way; use `@done` for results
