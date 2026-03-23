<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Analysis\PathCoverageTracker;
use Tarfinlabs\EventMachine\Tests\Stubs\Jobs\SuccessfulTestJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\JobActors\JobActorParentMachine;

beforeEach(function (): void {
    PathCoverageTracker::reset();
    PathCoverageTracker::enable();
});

afterEach(function (): void {
    PathCoverageTracker::reset();
});

test('assertPathCoverage passes when threshold is met', function (): void {
    $tm = TestMachine::create(JobActorParentMachine::class);
    $tm->send('START');
    $tm->simulateChildDone(SuccessfulTestJob::class);
    $tm->assertFinished();

    // 1 of 2 paths covered = 50%
    JobActorParentMachine::assertPathCoverage(minimum: 50.0);
});

test('assertPathCoverage fails when threshold is not met', function (): void {
    $tm = TestMachine::create(JobActorParentMachine::class);
    $tm->send('START');
    $tm->simulateChildDone(SuccessfulTestJob::class);
    $tm->assertFinished();

    JobActorParentMachine::assertPathCoverage(minimum: 100.0);
})->throws(AssertionFailedError::class, 'below minimum');

test('assertAllPathsCovered fails when paths are missing', function (): void {
    $tm = TestMachine::create(JobActorParentMachine::class);
    $tm->send('START');
    $tm->simulateChildDone(SuccessfulTestJob::class);
    $tm->assertFinished();

    JobActorParentMachine::assertAllPathsCovered();
})->throws(AssertionFailedError::class, 'untested path');

test('assertAllPathsCovered passes when all paths are covered', function (): void {
    // Happy path: @done
    $tm1 = TestMachine::create(JobActorParentMachine::class);
    $tm1->send('START');
    $tm1->simulateChildDone(SuccessfulTestJob::class);
    $tm1->assertFinished();

    // Fail path: @fail
    $tm2 = TestMachine::create(JobActorParentMachine::class);
    $tm2->send('START');
    $tm2->simulateChildFail(SuccessfulTestJob::class);
    $tm2->assertFinished();

    JobActorParentMachine::assertAllPathsCovered();
});
