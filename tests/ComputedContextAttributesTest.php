<?php

declare(strict_types=1);

use Spatie\LaravelData\Optional;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext;

it('can be computed context methods defined', function (): void {
    $context = new TrafficLightsContext(Optional::create(), Optional::create());

    expect($context->count)->toBe(1);
    expect($context->isCountEven())->toBeFalse();

    $context->count = 2;

    expect($context->isCountEven())->toBeTrue();
});
