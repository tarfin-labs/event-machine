<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext;

it('can be computed context methods defined', function (): void {
    $context = new TrafficLightsContext();

    expect($context->get('count'))->toBe(1);
    expect($context->isCountEven())->toBeFalse();

    $context->set('count', 2);

    expect($context->isCountEven())->toBeTrue();
});