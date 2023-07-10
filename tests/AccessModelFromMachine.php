<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Models\ModelA;

it('can access Laravel model from a machine context', function (): void {
    /** @var ModelA $a */
    $modelA = ModelA::create([
        'value' => 'some value',
    ]);

    $modelA->abc_mre->persist();

    $modelA->save();

    expect($modelA->abc_mre->state->context->get('modelA'))->toBe($modelA);
});

it('can access Laravel model from a machine context behavior', function (): void {
    /** @var ModelA $a */
    $modelA = ModelA::create([
        'value' => 'some value',
    ]);

    $modelA->traffic_mre->persist();

    $modelA->traffic_mre->send(['type' => 'INC']);

    expect($modelA->traffic_mre->state->context->modelA)->toBe($modelA);
});
