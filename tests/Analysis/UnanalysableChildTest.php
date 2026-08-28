<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ReentrantParallelMachine;
use Tarfinlabs\EventMachine\Fixtures\InvalidMachines\UnanalysableChildParentMachine;

/*
 * unhandledChildOutcomes() must skip a child whose definition cannot be built — one unbuildable
 * child must not take down the report for every other state. Skipping it SILENTLY was the
 * problem: the output then reads exactly like "every outcome is handled".
 */

test('a child whose definition throws is named instead of silently skipped', function (): void {
    $result = UnanalysableChildParentMachine::definition()->enumeratePaths();

    // The precondition: the silent-skip path is the one being exercised. If the child could be
    // built, this test would pass for the wrong reason.
    expect($result->unhandledChildOutcomes())->toBe([]);

    $unanalysable = $result->unanalysableChildren();

    expect($unanalysable)->toHaveCount(1)
        ->and($unanalysable[0]['parentStateKey'])->toBe('delegating')
        ->and($unanalysable[0]['childClass'])->toContain('ThrowingDefinitionMachine')
        ->and($unanalysable[0]['reason'])->toContain('App\\Actions\\Missing');
});

test('a machine whose children all build reports nothing unanalysable', function (): void {
    // The control: the warning must not fire for an ordinary machine.
    $result = ReentrantParallelMachine::definition()->enumeratePaths();

    expect($result->unanalysableChildren())->toBe([]);
});

test('machine:paths warns about a child it could not analyse', function (): void {
    $exit = Artisan::call('machine:paths', ['machine' => UnanalysableChildParentMachine::class]);

    $output = Artisan::output();

    // Exit code stays 0: this is disclosure, not a gate, matching how truncation and unmatched
    // observations are reported.
    expect($exit)->toBe(0)
        ->and($output)->toContain('COULD NOT BE ANALYSED')
        ->and($output)->toContain('ThrowingDefinitionMachine')
        ->and($output)->not->toContain('UNHANDLED CHILD OUTCOMES');
});

test('machine:paths --json carries the unanalysable children', function (): void {
    Artisan::call('machine:paths', ['machine' => UnanalysableChildParentMachine::class, '--json' => true]);

    $json = json_decode(Artisan::output(), true);

    expect($json['stats']['unanalysable_children'])->toHaveCount(1)
        ->and($json['stats']['unanalysable_children'][0]['child_class'])->toBe('ThrowingDefinitionMachine')
        ->and($json['stats']['unanalysable_children'][0]['parent_state'])->toBe('delegating');
});
