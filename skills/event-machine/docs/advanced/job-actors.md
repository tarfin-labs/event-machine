# Job Actors

Job actors let you invoke a Laravel Job as a child actor — ideal for single-step async operations that don't need a full state machine.

## Config Syntax

### Managed Job (with @done/@fail)

The parent waits for the job to complete and routes `@done` or `@fail`:

<!-- doctest-attr: ignore -->
```php
'sending_email' => [
    'job'      => SendWelcomeEmailJob::class,
    'input'     => ['email', 'name'],
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
    'input'   => ['action', 'userId'],
    'target' => 'next_state',
],
```

The parent does not track the job's output. If the job fails, it goes to Laravel's `failed_jobs` table.

## Returning Output

Jobs that implement `ReturnsOutput` can return data to the parent:

<!-- doctest-attr: no_run -->
```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Tarfinlabs\EventMachine\Contracts\ReturnsOutput;

class SendWelcomeEmailJob implements ShouldQueue, ReturnsOutput
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

    public function output(): array
    {
        return ['messageId' => $this->messageId];
    }
}
```

The parent receives the result via `ChildMachineDoneEvent->output()`:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Behavior\ChildMachineDoneEvent;

class StoreEmailOutputAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, ChildMachineDoneEvent $event): void
    {
        $context->set('messageId', $event->output('messageId'));
    }
}
```

Jobs that do **not** implement `ReturnsOutput` return an empty output (`[]`).

### Typed Output with MachineOutput

Jobs can also return a `MachineOutput` DTO for typed contracts:

<!-- doctest-attr: no_run -->
```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Tarfinlabs\EventMachine\Contracts\ReturnsOutput;
use Tarfinlabs\EventMachine\Behavior\MachineOutput;

class EmailOutput extends MachineOutput
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $status,
    ) {}
}

class SendWelcomeEmailJob implements ShouldQueue, ReturnsOutput
{
    public function __construct(
        public readonly string $email,
    ) {}

    public function handle(): void
    {
        $this->messageId = 'msg_abc123';
    }

    public function output(): EmailOutput
    {
        return new EmailOutput(
            messageId: $this->messageId,
            status: 'sent',
        );
    }
}
```

## Returning Failure Context

By default, when a job throws an exception, only `$exception->getMessage()` and `$exception->getCode()` are available to `@fail` guards. For structured error data (error codes, retry hints, categories), implement `ProvidesFailure`:

<!-- doctest-attr: no_run -->
```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Tarfinlabs\EventMachine\Contracts\ProvidesFailure;

class ConfirmPinJob implements ShouldQueue, ProvidesFailure
{
    public function __construct(
        public readonly string $pin,
    ) {}

    public function handle(): void
    {
        // ... may throw FindeksException with error code E311
    }

    public static function failure(\Throwable $exception): array
    {
        if ($exception instanceof FindeksException) {
            return [
                'errorCode' => $exception->getFindeksErrorCode(),
                'retryable' => $exception->isRetryable(),
            ];
        }

        return ['errorCode' => 'UNKNOWN'];
    }
}
```

The returned array becomes available via `$event->output()` in `@fail` guards and actions:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;
use Tarfinlabs\EventMachine\Behavior\ChildMachineFailEvent;

class IsPinRetryableGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context, ChildMachineFailEvent $event): bool
    {
        return in_array($event->output('errorCode'), ['E311', 'E116', 'E117'], true);
    }
}
```

### ChildMachineFailEvent API

| Accessor | Source | Always Available |
|----------|--------|-----------------|
| `errorMessage()` | `$exception->getMessage()` | Yes |
| `errorCode()` | `$exception->getCode()` | Yes |
| `output(?string $key)` | `ProvidesFailure::failure()` | Only with contract |
| `childMachineId()` | Job tracking ID | Yes |
| `childMachineClass()` | Job FQCN | Yes |

::: tip ReturnsOutput vs ProvidesFailure
`ReturnsOutput` populates `$event->output()` on `@done`. `ProvidesFailure` populates `$event->output()` on `@fail`. They complement each other -- a job can implement both.
:::

## Machine vs Job

| Aspect | `machine` | `job` |
|--------|-----------|-------|
| Stateful | Yes (multiple states) | No (single step) |
| Context | Own ContextManager | Data from `input` |
| Lifecycle | `@done` / `@fail` / `@timeout` | `@done` / `@fail` / `@timeout` |
| Fire-and-forget | Yes (omit `@done`, requires `queue`) | Yes (`target` key) |
| Output | `output` key on final state | `ReturnsOutput` interface |
| Testing | `Machine::fake()` | `Queue::fake()` + `ChildJobJob` |
| Use case | Complex stateful workflows | Single-step async operations |

## Context Transfer

The `input` key works the same way as machine delegation — same three formats:

<!-- doctest-attr: ignore -->
```php
// Same-name: ['email'] → job receives email from parent
// Rename: ['recipient' => 'email'] → job.recipient = parent.email
// Closure: fn(ContextManager $ctx) => ['to' => $ctx->get('email')]
```

## Validation Rules

| Config | Result |
|--------|--------|
| `job` + `machine` | `InvalidStateConfigException`: mutually exclusive |
| `job` + `type: parallel` | `InvalidStateConfigException`: not supported |
| `job` without `@done` or `target` | `InvalidStateConfigException`: must define one |
| `job` + `@done` + `target` | `InvalidStateConfigException`: ambiguous |
| `job` + `@done` | OK: managed job |
| `job` + `target` | OK: fire-and-forget |

The `job` class itself is validated at dispatch time. `InvalidJobClassException` is thrown if the class does not exist or does not have a `handle()` method.

## Queue Configuration

Jobs support the same queue options as machine delegation:

<!-- doctest-attr: ignore -->
```php
'processing' => [
    'job'        => ProcessDataJob::class,
    'input'       => ['data'],
    'queue'      => 'heavy',
    'connection' => 'redis',
    '@done'      => 'processed',
],
```

## Testing Job Actors

<!-- doctest-attr: ignore -->
```php
use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Jobs\ChildJobJob;

Queue::fake();

OrderMachine::test()
    ->send('SEND_NOTIFICATION')
    ->assertState('notified');

Queue::assertPushed(ChildJobJob::class, function (ChildJobJob $job): bool {
    return $job->jobClass === SendEmailJob::class;
});
```

### Testing Job Completion Routing

To test `@done`/`@fail` routing without running jobs, use `simulateChildDone()` — the same method used for machine delegation:

<!-- doctest-attr: no_run -->
```php
Queue::fake();

MyMachine::test()
    ->withoutPersistence()
    ->send('START')
    ->assertState('processing')
    ->simulateChildDone(MyJob::class, output: ['status' => 'ok'])
    ->assertState('completed');
```

This works because job actors and machine children share the same completion routing infrastructure.

### Queue::fake() vs simulateChildDone()

| Goal | Tool |
|------|------|
| Verify job was dispatched | `Queue::fake()` + `Queue::assertPushed(ChildJobJob::class)` |
| Verify dispatch data | `Queue::assertPushed(ChildJobJob::class, fn($job) => ...)` |
| Test `@done` routing | `simulateChildDone(MyJob::class, output: [...])` |
| Test `@fail` routing | `simulateChildFail(MyJob::class, errorMessage: '...')` |
| Test `@timeout` handling | `simulateChildTimeout(MyJob::class)` |
| Full pipeline with Horizon | LocalQA tests |

::: tip Full Testing Guide
See [Testing Job Actors](/testing/delegation-testing#testing-job-actors) for more examples.
:::
