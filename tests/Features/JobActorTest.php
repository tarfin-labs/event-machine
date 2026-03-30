<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Jobs\ChildJobJob;
use Tarfinlabs\EventMachine\Jobs\ChildMachineJob;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Contracts\ReturnsOutput;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Jobs\ChildMachineCompletionJob;
use Tarfinlabs\EventMachine\Exceptions\InvalidJobClassException;
use Tarfinlabs\EventMachine\Tests\Stubs\Jobs\FakeExternalService;
use Tarfinlabs\EventMachine\Exceptions\InvalidStateConfigException;
use Tarfinlabs\EventMachine\Exceptions\InvalidMachineClassException;
use Tarfinlabs\EventMachine\Tests\Stubs\Jobs\ExternalServiceContract;
use Tarfinlabs\EventMachine\Tests\Stubs\Jobs\DependencyInjectedTestJob;

// ─── Job Actor Config Validation ──────────────────────────────────

it('validates job + machine mutual exclusivity', function (): void {
    MachineDefinition::define(
        config: [
            'id'      => 'invalid',
            'initial' => 'test',
            'states'  => [
                'test' => [
                    'job'     => 'App\\Jobs\\SomeJob',
                    'machine' => 'App\\Machines\\SomeMachine',
                    '@done'   => 'done',
                ],
                'done' => ['type' => 'final'],
            ],
        ],
    );
})->throws(InvalidStateConfigException::class, "cannot have both 'job' and 'machine'");

it('validates job without @done requires target', function (): void {
    MachineDefinition::define(
        config: [
            'id'      => 'invalid',
            'initial' => 'test',
            'states'  => [
                'test' => [
                    'job' => 'App\\Jobs\\SomeJob',
                    // no @done, no target
                ],
                'done' => ['type' => 'final'],
            ],
        ],
    );
})->throws(InvalidStateConfigException::class, "without '@done' or 'target'");

it('validates @done + target ambiguity', function (): void {
    MachineDefinition::define(
        config: [
            'id'      => 'invalid',
            'initial' => 'test',
            'states'  => [
                'test' => [
                    'job'    => 'App\\Jobs\\SomeJob',
                    '@done'  => 'done',
                    'target' => 'done',
                ],
                'done' => ['type' => 'final'],
            ],
        ],
    );
})->throws(InvalidStateConfigException::class, "cannot have both '@done' and 'target'");

// ─── Managed Job Actor (@done) ────────────────────────────────────

it('dispatches ChildJobJob when entering a state with job key', function (): void {
    Queue::fake();

    $machine = MachineDefinition::define(
        config: [
            'id'      => 'job_parent',
            'initial' => 'idle',
            'context' => ['email' => 'test@example.com'],
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'sending'],
                ],
                'sending' => [
                    'job'   => 'App\\Jobs\\SendEmailJob',
                    'input' => ['email'],
                    '@done' => 'sent',
                    '@fail' => 'failed',
                ],
                'sent'   => ['type' => 'final'],
                'failed' => ['type' => 'final'],
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'START'], state: $state);

    Queue::assertPushed(ChildJobJob::class, function (ChildJobJob $job): bool {
        return $job->jobClass === 'App\\Jobs\\SendEmailJob'
            && $job->jobData === ['email' => 'test@example.com']
            && $job->fireAndForget === false;
    });
});

// ─── Fire-and-Forget Job Actor ────────────────────────────────────

it('dispatches fire-and-forget job and transitions immediately', function (): void {
    Queue::fake();

    $machine = MachineDefinition::define(
        config: [
            'id'      => 'ff_parent',
            'initial' => 'idle',
            'context' => ['action' => 'login'],
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'logging'],
                ],
                'logging' => [
                    'job'    => 'App\\Jobs\\AuditLogJob',
                    'input'  => ['action'],
                    'target' => 'next_state',
                ],
                'next_state' => ['type' => 'final'],
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'START'], state: $state);

    // Parent should have transitioned immediately to target
    expect($state->value)->toBe(['ff_parent.next_state']);

    // Job should be dispatched as fire-and-forget
    Queue::assertPushed(ChildJobJob::class, function (ChildJobJob $job): bool {
        return $job->jobClass === 'App\\Jobs\\AuditLogJob'
            && $job->fireAndForget === true;
    });
});

// ─── ChildJobJob execution ───────────────────────────────────────

it('ChildJobJob runs job and dispatches completion with result', function (): void {
    Queue::fake();

    // Create a test job that implements ReturnsOutput
    $testJobClass = new class() implements ReturnsOutput {
        public string $messageId = '';

        public function handle(): void
        {
            $this->messageId = 'msg_123';
        }

        public function output(): array
        {
            return ['message_id' => $this->messageId];
        }
    };

    // Bind the anonymous class in the container
    $className = $testJobClass::class;
    app()->bind($className, fn () => new $className());

    $job = new ChildJobJob(
        parentRootEventId: 'parent-root-id',
        parentMachineClass: 'App\\Machines\\ParentMachine',
        parentStateId: 'parent.sending',
        jobClass: $className,
    );

    $job->handle();

    Queue::assertPushed(ChildMachineCompletionJob::class, function (ChildMachineCompletionJob $completionJob): bool {
        return $completionJob->success === true
            && $completionJob->outputData === ['message_id' => 'msg_123'];
    });
});

it('ChildJobJob dispatches failure on exception', function (): void {
    Queue::fake();

    $failingJobClass = new class() {
        public function handle(): void
        {
            throw new RuntimeException('Email service unavailable');
        }
    };

    $className = $failingJobClass::class;
    app()->bind($className, fn () => new $className());

    $job = new ChildJobJob(
        parentRootEventId: 'parent-root-id',
        parentMachineClass: 'App\\Machines\\ParentMachine',
        parentStateId: 'parent.sending',
        jobClass: $className,
    );

    $job->failed(new RuntimeException('Email service unavailable'));

    Queue::assertPushed(ChildMachineCompletionJob::class, function (ChildMachineCompletionJob $completionJob): bool {
        return $completionJob->success === false
            && $completionJob->errorMessage === 'Email service unavailable';
    });
});

it('ChildJobJob fire-and-forget does not dispatch completion', function (): void {
    Queue::fake();

    $testJobClass = new class() {
        public function handle(): void
        {
            // do nothing
        }
    };

    $className = $testJobClass::class;
    app()->bind($className, fn () => new $className());

    $job = new ChildJobJob(
        parentRootEventId: 'parent-root-id',
        parentMachineClass: 'App\\Machines\\ParentMachine',
        parentStateId: 'parent.logging',
        jobClass: $className,
        fireAndForget: true,
    );

    $job->handle();

    Queue::assertNotPushed(ChildMachineCompletionJob::class);
});

// ─── Dependency Injection ────────────────────────────────────────

it('ChildJobJob resolves handle() dependencies via service container', function (): void {
    Queue::fake();

    // Bind the contract to its implementation in the container
    app()->bind(
        ExternalServiceContract::class,
        FakeExternalService::class,
    );

    $job = new ChildJobJob(
        parentRootEventId: 'parent-root-id',
        parentMachineClass: 'App\\Machines\\ParentMachine',
        parentStateId: 'parent.processing',
        jobClass: DependencyInjectedTestJob::class,
    );

    $job->handle();

    Queue::assertPushed(ChildMachineCompletionJob::class, function (ChildMachineCompletionJob $completionJob): bool {
        return $completionJob->success === true
            && $completionJob->outputData === ['serviceData' => 'fake-result'];
    });
});

// ─── Class validation ────────────────────────────────────────────

it('ChildJobJob rejects non-existent job class', function (): void {
    $job = new ChildJobJob(
        parentRootEventId: 'parent-root-id',
        parentMachineClass: 'App\\Machines\\ParentMachine',
        parentStateId: 'parent.sending',
        jobClass: 'App\\Jobs\\NonExistentJob',
    );

    $job->handle();
})->throws(InvalidJobClassException::class, 'does not exist');

it('ChildJobJob rejects job class without handle method', function (): void {
    $noHandleClass = new class() {};
    $className     = $noHandleClass::class;
    app()->bind($className, fn () => new $className());

    $job = new ChildJobJob(
        parentRootEventId: 'parent-root-id',
        parentMachineClass: 'App\\Machines\\ParentMachine',
        parentStateId: 'parent.sending',
        jobClass: $className,
    );

    $job->handle();
})->throws(InvalidJobClassException::class, 'must have a handle() method');

it('SendToMachineJob rejects non-Machine class', function (): void {
    $job = new SendToMachineJob(
        machineClass: 'stdClass',
        rootEventId: 'test-root-id',
        event: ['type' => 'TEST'],
    );

    $job->handle();
})->throws(InvalidMachineClassException::class, 'must exist and extend');

it('ChildMachineJob rejects non-Machine class', function (): void {
    $job = new ChildMachineJob(
        parentRootEventId: 'parent-root-id',
        parentMachineClass: 'App\\Machines\\ParentMachine',
        parentStateId: 'parent.processing',
        childMachineClass: 'stdClass',
        machineChildId: 'test-child-id',
    );

    $job->handle();
})->throws(InvalidMachineClassException::class, 'must exist and extend');

it('ChildJobJob without ReturnsOutput returns empty output', function (): void {
    Queue::fake();

    $simpleJobClass = new class() {
        public function handle(): void
        {
            // no ReturnsOutput
        }
    };

    $className = $simpleJobClass::class;
    app()->bind($className, fn () => new $className());

    $job = new ChildJobJob(
        parentRootEventId: 'parent-root-id',
        parentMachineClass: 'App\\Machines\\ParentMachine',
        parentStateId: 'parent.processing',
        jobClass: $className,
    );

    $job->handle();

    Queue::assertPushed(ChildMachineCompletionJob::class, function (ChildMachineCompletionJob $completionJob): bool {
        return $completionJob->success === true
            && $completionJob->outputData === [];
    });
});
