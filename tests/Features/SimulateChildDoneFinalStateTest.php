<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\AssertionFailedError;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\JobActors\SuccessfulTestJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SameLeafChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\MultiOutcomeChildMachine;

function makeMultiOutcomeParent(): TestMachine
{
    $testMachine = TestMachine::define(
        config: [
            'id'      => 'finalstate_parent',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'delegating'],
                ],
                'delegating' => [
                    'machine'        => MultiOutcomeChildMachine::class,
                    'queue'          => 'default',
                    '@done.approved' => 'accepted',
                    '@done.rejected' => 'declined',
                    '@done'          => 'fallback',
                ],
                'accepted' => ['type' => 'final'],
                'declined' => ['type' => 'final'],
                'fallback' => ['type' => 'final'],
            ],
        ],
    );

    $testMachine->machine()->definition->machineClass = 'InlineFinalStateParent';

    return $testMachine->send('GO')->assertState('delegating');
}

it('accepts a valid leaf finalState', function (): void {
    Queue::fake();

    makeMultiOutcomeParent()
        ->simulateChildDone(MultiOutcomeChildMachine::class, finalState: 'rejected')
        ->assertState('declined');
});

it('accepts a full dotted id and normalizes it to the leaf for routing', function (): void {
    Queue::fake();

    // If the dotted id leaked through un-normalized, @done.rejected would not
    // match and the machine would fall through to the @done catch-all.
    makeMultiOutcomeParent()
        ->simulateChildDone(MultiOutcomeChildMachine::class, finalState: 'multi_outcome_child.rejected')
        ->assertState('declined');
});

it('rejects a finalState that is not a final state of the child', function (): void {
    Queue::fake();

    $testMachine = makeMultiOutcomeParent();

    try {
        $testMachine->simulateChildDone(MultiOutcomeChildMachine::class, finalState: 'nonexistent');
        $this->fail('Expected an assertion failure.');
    } catch (AssertionFailedError $error) {
        expect($error->getMessage())
            ->toContain('[nonexistent] is not a final state')
            ->toContain('Final states: [');
    }
});

it('accepts a leaf name shared by multiple final states', function (): void {
    Queue::fake();

    $testMachine = TestMachine::define(
        config: [
            'id'      => 'same_leaf_parent',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'delegating'],
                ],
                'delegating' => [
                    'machine'    => SameLeafChildMachine::class,
                    'queue'      => 'default',
                    '@done.done' => 'completed',
                    '@done'      => 'fallback',
                ],
                'completed' => ['type' => 'final'],
                'fallback'  => ['type' => 'final'],
            ],
        ],
    );

    $testMachine->machine()->definition->machineClass = 'InlineSameLeafParent';

    $testMachine
        ->send('GO')
        ->assertState('delegating')
        ->simulateChildDone(SameLeafChildMachine::class, finalState: 'done')
        ->assertState('completed');
});

it('skips finalState validation for job actors', function (): void {
    Queue::fake();

    $testMachine = TestMachine::define(
        config: [
            'id'      => 'job_finalstate_parent',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'processing'],
                ],
                'processing' => [
                    'job'   => SuccessfulTestJob::class,
                    'queue' => 'default',
                    '@done' => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    $testMachine->machine()->definition->machineClass = 'InlineJobFinalStateParent';

    // Jobs have no state tree — an arbitrary finalState must not be validated.
    $testMachine
        ->send('GO')
        ->assertState('processing')
        ->simulateChildDone(SuccessfulTestJob::class, finalState: 'anything-goes')
        ->assertState('completed');
});
