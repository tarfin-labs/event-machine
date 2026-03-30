<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;

// ═══════════════════════════════════════════════════════════════
//  ForwardContext Removal
// ═══════════════════════════════════════════════════════════════

test('ForwardContext class no longer exists', function (): void {
    expect(class_exists('Tarfinlabs\EventMachine\Routing\ForwardContext'))->toBeFalse();
});

// ═══════════════════════════════════════════════════════════════
//  InvokableBehavior Parameter Injection
// ═══════════════════════════════════════════════════════════════

test('InvokableBehavior does not accept ForwardContext parameter', function (): void {
    $reflection = new ReflectionMethod(InvokableBehavior::class, 'injectInvokableBehaviorParameters');

    $paramNames = array_map(
        fn (ReflectionParameter $p) => $p->getName(),
        $reflection->getParameters(),
    );

    expect($paramNames)->not->toContain('forwardContext');
});

test('InvokableBehavior accepts childOutput parameter', function (): void {
    $reflection = new ReflectionMethod(InvokableBehavior::class, 'injectInvokableBehaviorParameters');

    $paramNames = array_map(
        fn (ReflectionParameter $p) => $p->getName(),
        $reflection->getParameters(),
    );

    expect($paramNames)->toContain('childOutput');
});
