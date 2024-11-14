<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Behavior\CalculatorBehavior;

class PriceCalculator extends CalculatorBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $quantity  = $context->get('quantity');
        $unitPrice = $context->get('unitPrice');
        $context->set('totalPrice', $quantity * $unitPrice);
    }
}

class MinimumGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        return $context->get('totalPrice') >= 100;
    }
}

class ApplyDiscountAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $totalPrice = $context->get('totalPrice');
        $finalPrice = (int) ($totalPrice * 0.9); // 10% discount

        $context->set('finalPrice', $finalPrice);
    }
}

test('calculator can set context values that guards and actions can use', function (): void {
    $machine = Machine::create([
        'config' => [
            'initial' => 'start',
            'context' => [
                'quantity'  => 10,
                'unitPrice' => 15,
            ],
            'states' => [
                'start' => [
                    'on' => [
                        'PROCESS' => [
                            'target'      => 'processed',
                            'calculators' => PriceCalculator::class,
                            'guards'      => MinimumGuard::class,
                            'actions'     => ApplyDiscountAction::class,
                        ],
                    ],
                ],
                'processed' => [],
            ],
        ],
    ]);

    $state = $machine->send(['type' => 'PROCESS']);

    expect($state->context->get('totalPrice'))->toBe(150);
    expect($state->context->get('finalPrice'))->toBe(135);
    expect($state->matches('processed'))->toBeTrue();
});
