<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\PathStep;
use Tarfinlabs\EventMachine\Analysis\PathType;
use Tarfinlabs\EventMachine\Analysis\MachinePath;

test('signature produces correct format for simple path', function (): void {
    $path = new MachinePath(
        steps: [
            new PathStep(stateId: 'machine.idle', stateKey: 'idle'),
            new PathStep(stateId: 'machine.done', stateKey: 'done', event: '@always'),
        ],
        type: PathType::HAPPY,
        terminalStateId: 'machine.done',
    );

    expect($path->signature())->toBe('idle→[@always]→done');
});

test('signature produces correct format for multi-step path', function (): void {
    $path = new MachinePath(
        steps: [
            new PathStep(stateId: 'machine.idle', stateKey: 'idle'),
            new PathStep(stateId: 'machine.processing', stateKey: 'processing', event: 'START'),
            new PathStep(stateId: 'machine.completed', stateKey: 'completed', event: '@done', invokeType: '@done'),
        ],
        type: PathType::HAPPY,
        terminalStateId: 'machine.completed',
    );

    expect($path->signature())->toBe('idle→[START]→processing→[@done]→completed');
});

test('signature appends stays for guard block paths', function (): void {
    $path = new MachinePath(
        steps: [
            new PathStep(stateId: 'machine.idle', stateKey: 'idle'),
        ],
        type: PathType::GUARD_BLOCK,
    );

    expect($path->signature())->toBe('idle→stays');
});

test('stateIds returns ordered state IDs', function (): void {
    $path = new MachinePath(
        steps: [
            new PathStep(stateId: 'machine.idle', stateKey: 'idle'),
            new PathStep(stateId: 'machine.processing', stateKey: 'processing', event: 'START'),
            new PathStep(stateId: 'machine.completed', stateKey: 'completed', event: '@done'),
        ],
        type: PathType::HAPPY,
        terminalStateId: 'machine.completed',
    );

    expect($path->stateIds())->toBe([
        'machine.idle',
        'machine.processing',
        'machine.completed',
    ]);
});

test('guardNames returns unique guards across steps', function (): void {
    $path = new MachinePath(
        steps: [
            new PathStep(stateId: 'a', stateKey: 'a', guards: ['isAllowed', 'isValid']),
            new PathStep(stateId: 'b', stateKey: 'b', event: 'GO', guards: ['isAllowed']),
        ],
        type: PathType::HAPPY,
        terminalStateId: 'b',
    );

    expect($path->guardNames())->toBe(['isAllowed', 'isValid']);
});

test('actionNames returns unique actions across steps', function (): void {
    $path = new MachinePath(
        steps: [
            new PathStep(stateId: 'a', stateKey: 'a', actions: ['logAction']),
            new PathStep(stateId: 'b', stateKey: 'b', event: 'GO', actions: ['logAction', 'saveAction']),
        ],
        type: PathType::HAPPY,
        terminalStateId: 'b',
    );

    expect($path->actionNames())->toBe(['logAction', 'saveAction']);
});
