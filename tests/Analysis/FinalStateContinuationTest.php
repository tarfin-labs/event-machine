<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\PathEnumerator;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * @return list<string>
 */
function finalPathSignatures(MachineDefinition $definition): array
{
    return array_map(
        static fn ($path): string => $path->signature(),
        (new PathEnumerator($definition))->enumerate()->paths,
    );
}

test('a final leaf still follows a handler declared on an ancestor', function (): void {
    // handleFinal consulted only the parent compound's @done, so a handler declared above
    // and inherited by a FINAL child was never followed. The runtime does take it —
    // findTransitionDefinition walks the parent chain with no special case for FINAL — so
    // the analysis was missing a route the machine can actually run, with no path, no
    // truncation flag, and a coverage figure that read 100% over the gap.
    $definition = MachineDefinition::define(config: [
        'id'      => 'final_inherits',
        'initial' => 'idle',
        'on'      => ['EXPIRED' => 'expired'],
        'states'  => [
            'idle'  => ['on' => ['GO' => 'inner']],
            'inner' => [
                'initial' => 'leaf',
                'states'  => ['leaf' => ['type' => 'final']],
            ],
            'expired' => ['type' => 'final'],
        ],
    ]);

    $signatures = finalPathSignatures($definition);

    // Resting at the final leaf is still an outcome, so that path survives...
    expect($signatures)->toContain('idle→[GO]→inner→leaf');

    // ...and the inherited handler is a second, equally real one. The assertion has to
    // name the leaf: `idle` inherits the same EXPIRED handler, so simply looking for
    // "[EXPIRED]" anywhere is satisfied by `idle→[EXPIRED]→expired` and passes even when
    // handleFinal follows nothing at all.
    expect($signatures)->toContain('idle→[GO]→inner→leaf→[EXPIRED]→expired');
});

test('a final leaf with nothing inherited is still just terminal', function (): void {
    // The counterpart: without an ancestor handler the final leaf records one path and
    // nothing else, so the fix above cannot be adding paths unconditionally.
    $definition = MachineDefinition::define(config: [
        'id'      => 'final_plain',
        'initial' => 'idle',
        'states'  => [
            'idle'  => ['on' => ['GO' => 'inner']],
            'inner' => [
                'initial' => 'leaf',
                'states'  => ['leaf' => ['type' => 'final']],
            ],
        ],
    ]);

    expect(finalPathSignatures($definition))->toBe(['idle→[GO]→inner→leaf']);
});

test('a compound @done still replaces the terminal path rather than joining it', function (): void {
    // @done fires on its own, so the machine never rests at the final leaf and only the
    // continuation is a real outcome. An inherited event handler is the opposite case —
    // it needs an event, so both outcomes exist. The two must not be conflated.
    $definition = MachineDefinition::define(config: [
        'id'      => 'final_done',
        'initial' => 'idle',
        'states'  => [
            'idle'  => ['on' => ['GO' => 'inner']],
            'inner' => [
                'initial' => 'leaf',
                '@done'   => 'settled',
                'states'  => ['leaf' => ['type' => 'final']],
            ],
            'settled' => ['type' => 'final'],
        ],
    ]);

    $signatures = finalPathSignatures($definition);

    expect($signatures)->toBe(['idle→[GO]→inner→leaf→[@done]→settled']);
});

test('a guarded compound @done leaves the final state reachable', function (): void {
    // An @done whose every branch is guarded may not fire, and then the machine rests at
    // the final child and can still be driven out by an inherited handler. handleFinal
    // returned as soon as the parent had ANY onDoneTransition, so both the rest-at-final
    // path and everything beyond the inherited event were absent with no truncation flag
    // — and coverage read 100% while assertAllPathsCovered() passed over the gap.
    $definition = MachineDefinition::define(config: [
        'id'      => 'guarded_done',
        'initial' => 'idle',
        'on'      => ['ESCAPE' => 'escaped'],
        'states'  => [
            'idle'  => ['on' => ['GO' => 'inner']],
            'inner' => [
                'initial' => 'leaf',
                '@done'   => [['target' => 'settled', 'guards' => 'neverGuard']],
                'states'  => ['leaf' => ['type' => 'final']],
            ],
            'settled' => ['type' => 'final'],
            'escaped' => ['type' => 'final'],
        ],
    ], behavior: [
        'guards' => ['neverGuard' => static fn (): bool => false],
    ]);

    $signatures = finalPathSignatures($definition);

    expect($signatures)->toContain('idle→[GO]→inner→leaf→[@done]→settled')
        ->and($signatures)->toContain('idle→[GO]→inner→leaf')
        ->and($signatures)->toContain('idle→[GO]→inner→leaf→[ESCAPE]→escaped');
});

test('an unguarded compound @done still replaces the terminal path', function (): void {
    // The control for the test above: an @done that always fires means the machine never
    // rests at the leaf, so the terminal path must NOT appear. Without this the fix could
    // record a rest-at-final path unconditionally and nothing would notice.
    $definition = MachineDefinition::define(config: [
        'id'      => 'unguarded_done',
        'initial' => 'idle',
        'on'      => ['ESCAPE' => 'escaped'],
        'states'  => [
            'idle'  => ['on' => ['GO' => 'inner']],
            'inner' => [
                'initial' => 'leaf',
                '@done'   => 'settled',
                'states'  => ['leaf' => ['type' => 'final']],
            ],
            'settled' => ['type' => 'final'],
            'escaped' => ['type' => 'final'],
        ],
    ]);

    $signatures = finalPathSignatures($definition);

    // The @done continuation is there, and the rest-at-final path is NOT: that absence is
    // the whole assertion. Listing the full set would be brittle for the wrong reason —
    // `settled` is itself a final state that now follows the machine-level ESCAPE, so the
    // set legitimately contains continuations past it.
    expect($signatures)->toContain('idle→[GO]→inner→leaf→[@done]→settled')
        ->and($signatures)->not->toContain('idle→[GO]→inner→leaf');
});
