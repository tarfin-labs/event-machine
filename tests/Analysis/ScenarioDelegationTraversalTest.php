<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\MachineGraph;
use Tarfinlabs\EventMachine\Analysis\StateClassification;
use Tarfinlabs\EventMachine\Analysis\ScenarioPathResolver;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioDelegationEdgeMachine;

function edgeResolver(): ScenarioPathResolver
{
    return new ScenarioPathResolver(new MachineGraph(ScenarioDelegationEdgeMachine::definition()));
}

test('a delegation state with none of the four keys terminates a path', function (): void {
    $resolver = edgeResolver();

    // One binding for both calls. The negative below pairs toBeEmpty() with wasTruncated() false,
    // which is exactly what an unresolvable event name produces — so a typo in a second literal
    // would leave this test green while asserting nothing. With one binding it cannot drift, and
    // the positive assertion becomes the canary proving the negative call is live.
    $trigger = 'FIRE_AND_FORGET';

    // As the target it is reachable, and priced at 3 like any other delegation state.
    $asTarget = $resolver->resolveAll('root', $trigger, 'fire_forget');

    expect($asTarget)->toHaveCount(1)
        ->and(scenarioStateKeys($asTarget[0]))->toBe(['fire_forget'])
        ->and($asTarget[0]->steps[0]->classification)->toBe(StateClassification::DELEGATION)
        ->and($asTarget[0]->totalWeight)->toBe(3);

    // Beyond it there is nothing. fire_forget names after_edge twice — once as the engine's
    // fire-and-forget `target` and once as an ordinary `on: CONTINUE` — and the resolver
    // follows neither, so after_edge is unreachable through it. This is an exhausted search,
    // not a truncated one, so the emptiness is a real answer rather than a cap.
    $beyond = $resolver->resolveAll('root', $trigger, 'after_edge');

    expect($beyond)->toBeEmpty()
        ->and($resolver->wasTruncated())->toBeFalse();
});

test('a delegation state is walked by its four keys and by nothing else', function (): void {
    $resolver = edgeResolver();

    // One binding for all three calls, so the negative cannot go vacuous on a typo.
    $trigger = 'PARTIAL';

    // Both delegation keys are followed, each reaching its own outcome.
    $viaDone = $resolver->resolveAll('root', $trigger, 'recovered');
    $viaFail = $resolver->resolveAll('root', $trigger, 'failed_out');

    expect($viaDone)->toHaveCount(1)
        ->and(scenarioStateKeys($viaDone[0]))->toBe(['fail_only', 'recovered'])
        // fail_only (DELEGATION 3) + recovered (FINAL 0)
        ->and($viaDone[0]->totalWeight)->toBe(3)
        ->and($viaDone[0]->steps[1]->event)->toBe('@done')
        ->and($viaFail)->toHaveCount(1)
        ->and($viaFail[0]->steps[1]->event)->toBe('@fail');

    // The ordinary `on: SKIP` on the same state contributes no successor at all, so after_edge
    // stays unreachable through it — an exhausted search, not a truncated one.
    $viaOrdinary = $resolver->resolveAll('root', $trigger, 'after_edge');

    expect($viaOrdinary)->toBeEmpty()
        ->and($resolver->wasTruncated())->toBeFalse();
});

test('a delegation state carrying fail and no done is still walked by fail', function (): void {
    $resolver = edgeResolver();

    // One binding for both calls, so the negative cannot go vacuous on a typo.
    $trigger = 'PURE';

    // fail_pure has @fail and NO @done. Collecting @fail is not conditional on @done being
    // present beside it, which is the half the both-keys state above cannot show.
    $viaFail = $resolver->resolveAll('root', $trigger, 'failed_out');

    expect($viaFail)->toHaveCount(1)
        ->and(scenarioStateKeys($viaFail[0]))->toBe(['fail_pure', 'failed_out'])
        // fail_pure (DELEGATION 3) + failed_out (FINAL 0)
        ->and($viaFail[0]->totalWeight)->toBe(3)
        ->and($viaFail[0]->steps[1]->event)->toBe('@fail');

    // fail_pure names after_edge twice — as the engine's fire-and-forget `target` and as an
    // ordinary `on: SKIP` — and neither is a successor. This is the half fail_only cannot show:
    // it has @done, so "falls back to on: when @done is absent" survives every assertion made
    // against it. Here @done IS absent and the ordinary transition is still not followed.
    $viaOrdinary = $resolver->resolveAll('root', $trigger, 'after_edge');

    expect($viaOrdinary)->toBeEmpty()
        ->and($resolver->wasTruncated())->toBeFalse();
});
