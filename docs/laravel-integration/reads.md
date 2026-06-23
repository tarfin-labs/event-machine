# Reads — Read-Only Projections

`reads` expose a machine's current state over HTTP as **queries**: zero-write, GET-only
projections. They are the read side of the command/query split — [`endpoints`](endpoints.md)
are commands (they send events and write history); `reads` only *look*.

## Why Reads?

Reading a machine's state is not an event. In state-machine terms you inspect the current
configuration (`state.value`, the available events); in CQRS terms a query must never produce
events. Modelling a "what state am I in?" poll as a targetless event is a category error — and
a costly one: every such request runs the full `send()` → `persist()` pipeline and appends
rows to `machine_events`. A frontend polling every few seconds can bloat a single machine's
history to thousands of rows and, eventually, wedge it.

A read avoids all of that **by construction**: it restores the machine, reads its current
state, and returns the standard response envelope. It never constructs an event, never calls
`send()`/`persist()`, never acquires a lock, and never writes a row.

::: tip One exception — archived machines
The zero-write guarantee covers active machines. Reading a machine whose events have been
moved to the archive triggers the existing transparent auto-restore, which *does* write
(it pulls events back out of the archive). That is a property of restoration, not of the
read itself.
:::

## Defining Reads

Reads are declared in `MachineRouter::register()`, alongside the rest of the HTTP wiring:

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Routing\MachineRouter;

MachineRouter::register(OrderWorkflowMachine::class, [
    'prefix' => 'orders',
    'reads'  => [
        'status' => null,                                  // GET orders/{machineId}/status
        'resume' => ['output' => ResumeOutput::class],     // GET orders/{machineId}/resume
    ],
]);
```

`reads` accepts three forms:

| Form | Meaning |
|------|---------|
| (absent) | No read routes (default — no silent exposure). |
| `'reads' => true` | A single default read named `status` with the standard envelope. Exactly equivalent to `['status' => null]`. |
| `'reads' => ['status' => null, ...]` | One read route per entry. The key is the URI suffix; the value is `null`, a string URI-shorthand, or an options array. |

Reads always bind by machine id — `GET {prefix}/{machineId}/{uri}` — even when the
registration's endpoints are model-bound. A read targets a machine instance by its
`root_event_id`, not a domain model.

## Read Options

When a read's value is an options array:

| Option | Type | Default | Meaning |
|--------|------|---------|---------|
| `uri` | `string` | the key | Override the URI suffix. Normalized: surrounding slashes stripped, one leading `/` added. |
| `output` | `null \| string \| array` | `null` | Shape the response `output` field (see [Output](#output)). |
| `middleware` | `string[]` | `[]` | Per-read middleware, on top of the group middleware. |
| `status` | `int` | `200` | HTTP status code. |
| `available_events` | `bool \| null` | `null` | `null`/`true` include `availableEvents`; `false` omits the key. |

`action` and `method` are rejected — reads are pure and GET-only. Unknown keys, duplicate
URIs, and invalid URIs (empty, a bare `/`, or containing a route placeholder like `{id}`) all
throw `InvalidRouterConfigException` at registration.

## Response Envelope

A read returns the same envelope as event endpoints:

<!-- doctest-attr: ignore -->
```json
{ "data": { "id", "machineId", "state", "availableEvents", "output", "isProcessing" } }
```

`isProcessing` is always `false` for reads. When `available_events` is `false`, the
`availableEvents` key is omitted entirely.

## Output

A read's `output` shapes the `output` field exactly as an endpoint's `output` does:

| `output` | Result |
|----------|--------|
| `null` | Falls back to the machine's own `output()` (or `null`). |
| a list of context keys (`['orderId', 'total']`) | Filters the context to those keys. |
| an `OutputBehavior` key/class | Runs the behavior to compute a custom shape. |

This lets a light `status` read and a heavy `resume` read coexist — `status` returns just
state + available events, while `resume` projects the full context needed to rebuild a form:

<!-- doctest-attr: ignore -->
```php
'reads' => [
    'status' => null,                                      // light: poll-friendly
    'resume' => ['output' => ResumeOutput::class],         // heavy: full context for the UI
],
```

## Errors

| Condition | Response |
|-----------|----------|
| Unknown `machineId` | `404` |
| `machineId` from a different machine class | `404` |
| Archived machine | `200` — restored transparently |

## Migrating a Read-as-Event Endpoint (optional)

If you currently model a poll as a targetless event, you can move it to a read. This is
optional — an app that hits no errors need not migrate:

1. Remove the event entry from the machine's `endpoints:` config.
2. Delete the now-unused `*RequestedEvent` class.
3. Add the read to `MachineRouter::register(['reads' => ...])`.
4. Move any endpoint `output` shaper to the read's `output`.

The poll then stops writing to `machine_events` entirely.

## See Also

- [HTTP Endpoints](endpoints.md) — the command side (events that write).
- [Persistence](persistence.md) — how history is stored, and the write-path hardening.
