# Job Actors

Job actors let you invoke a Laravel Job as a child actor — ideal for single-step async operations that don't need a full state machine.

## Config Syntax

### Managed Job (with @done/@fail)

The parent waits for the job to complete and routes `@done` or `@fail`:

<!-- doctest-attr: ignore -->
```php
'sending_email' => [
    'job'      => SendWelcomeEmailJob::class,
    'with'     => ['email', 'name'],
    '@done'    => 'email_sent',
    '@fail'    => 'email_failed',
    '@timeout' => ['target' => 'timed_out', 'after' => 300],
],
```

### Fire-and-Forget Job

No `@done`/`@fail` — the job is dispatched and the parent transitions immediately to `target`:

<!-- doctest-attr: ignore -->
```php
'logging' => [
    'job'    => AuditLogJob::class,
    'with'   => ['action', 'user_id'],
    'target' => 'next_state',
],
```

The parent does not track the job's result. If the job fails, it goes to Laravel's `failed_jobs` table.

## Returning Output

Jobs that implement `ReturnsResult` can return data to the parent:

<!-- doctest-attr: no_run -->
```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Tarfinlabs\EventMachine\Contracts\ReturnsResult;

class SendWelcomeEmailJob implements ShouldQueue, ReturnsResult
{
    public function __construct(
        public readonly string $email,
        public readonly string $name,
    ) {}

    public function handle(): void
    {
        // ... send email ...
        $this->messageId = 'msg_abc123';
    }

    public function result(): array
    {
        return ['message_id' => $this->messageId];
    }
}
```

The parent receives the result via `ChildMachineDoneEvent->output()`:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Behavior\ChildMachineDoneEvent;

class StoreEmailResultAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, ChildMachineDoneEvent $event): void
    {
        $context->set('message_id', $event->output('message_id'));
    }
}
```

Jobs that do **not** implement `ReturnsResult` return an empty output (`[]`).

## Machine vs Job

| Aspect | `machine` | `job` |
|--------|-----------|-------|
| Stateful | Yes (multiple states) | No (single step) |
| Context | Own ContextManager | Data from `with` |
| Lifecycle | `@done` / `@fail` / `@timeout` | `@done` / `@fail` / `@timeout` |
| Fire-and-forget | Yes (omit `@done`, requires `queue`) | Yes (`target` key) |
| Output | `output` key on final state | `ReturnsResult` interface |
| Testing | `Machine::fake()` | `Queue::fake()` + `ChildJobJob` |
| Use case | Complex stateful workflows | Single-step async operations |

## Context Transfer

The `with` key works the same way as machine delegation — same three formats:

<!-- doctest-attr: ignore -->
```php
// Same-name: ['email'] → job receives email from parent
// Rename: ['recipient' => 'email'] → job.recipient = parent.email
// Closure: fn(ContextManager $ctx) => ['to' => $ctx->get('email')]
```

## Validation Rules

| Config | Result |
|--------|--------|
| `job` + `machine` | Error: mutually exclusive |
| `job` + `type: parallel` | Error: not supported |
| `job` without `@done` or `target` | Error: must define one |
| `job` + `@done` + `target` | Error: ambiguous |
| `job` + `@done` | OK: managed job |
| `job` + `target` | OK: fire-and-forget |

## Queue Configuration

Jobs support the same queue options as machine delegation:

<!-- doctest-attr: ignore -->
```php
'processing' => [
    'job'        => ProcessDataJob::class,
    'with'       => ['data'],
    'queue'      => 'heavy',
    'connection' => 'redis',
    '@done'      => 'processed',
],
```
