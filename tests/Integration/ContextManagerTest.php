<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Tarfinlabs\EventMachine\Context;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Models\ModelA;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\MoneyValue;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AbcMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\LineItemDto;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\OrderContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\MoneyValueCast;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\PaymentContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\InvalidCastContext;
use Tarfinlabs\EventMachine\Exceptions\MachineContextValidationException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

it('can initialize an empty context manager', function (): void {
    $context = new Context();

    expect($context)->toBeInstanceOf(Context::class);
});

it('can set and get context manager data', function (): void {
    $context = new Context();

    $key1   = 'key1';
    $value1 = 'value1';

    $key2   = 'key2';
    $value2 = 'value2';

    $return1 = $context->set(key: $key1, value: $value1);
    $return2 = $context->set(key: $key2, value: $value2);

    expect($context->get(key: $key1))->toBe($value1);
    expect($return1)->toBe($value1);

    expect($context->get(key: $key2))->toBe($value2);
    expect($return2)->toBe($value2);
});

it('can set and get context manager data for context class', function (): void {
    $context = TrafficLightsContext::from([
        'count' => 1,
    ]);

    $key   = 'count';
    $value = 6;

    $return = $context->set(key: $key, value: $value);

    expect($context->get(key: $key))->toBe($value);
    expect($return)->toBe($value);
});

it('returns null for non-existent keys', function (): void {
    $context = new Context();

    expect($context->get(key: 'non_existent_key'))->toBeNull();
});

it('can check if a key exists', function (): void {
    $context = new Context();
    $context->set(key: 'key1', value: 'value1');

    expect($context->has(key: 'key1'))->toBeTrue();
    expect($context->has(key: 'non_existent_key'))->toBeFalse();
});

it('can check if a key exists for context class', function (): void {
    $context = TrafficLightsContext::from([
        'count' => 1,
    ]);

    expect($context->has(key: 'count'))->toBeTrue();
    expect($context->has(key: 'asd'))->toBeFalse();
});

it('can remove a key from context data', function (): void {
    $context = new Context();

    $context->set(key: 'key1', value: 'value1');
    $context->set(key: 'key2', value: 'value2');
    $context->remove(key: 'key1');

    expect($context->has(key: 'key1'))->toBeFalse();
    expect($context->has(key: 'key2'))->toBeTrue();
});

it('can initialize context data with an array', function (): void {
    $initialData = ['key1' => 'value1', 'key2' => 'value2'];
    $context     = Context::from($initialData);

    expect($context->get(key: 'key1'))->toBe('value1');
    expect($context->get(key: 'key2'))->toBe('value2');
});

it('can convert context data to an array', function (): void {
    $initialData = ['key1' => 'value1', 'key2' => 'value2'];
    $context     = Context::from($initialData);

    $contextArray = $context->toArray();

    expect($contextArray)->toBeArray();
    expect($contextArray)->toHaveCount(2);
    expect($contextArray['key1'])->toBe('value1');
    expect($contextArray['key2'])->toBe('value2');
});

it('can handle edge cases with empty keys and values', function (): void {
    $context = new Context();

    $context->set(key: '', value: 'empty_key_value');
    $context->set(key: 'empty_value_key', value: '');

    expect($context->get(key: ''))->toBe('empty_key_value');
    expect($context->get(key: 'empty_value_key'))->toBe('');

    expect($context->has(key: ''))->toBeTrue();
    expect($context->has(key: 'empty_value_key'))->toBeTrue();

    $context->remove(key: '');
    expect($context->has(key: ''))->toBeFalse();
    expect($context->has(key: 'empty_value_key'))->toBeTrue();

    $contextArray = $context->toArray();
    expect($contextArray)->toHaveCount(1);
    expect($contextArray['empty_value_key'])->toBe('');
});

test('TrafficLightsMachine transitions between states using EventMachine', function (): void {
    $machineDefinition = TrafficLightsMachine::definition();

    $machineDefinition->transition(event: [
        'type'    => 'SUBTRACT_VALUE',
        'payload' => ['value' => 100],
    ]);
})->throws(MachineContextValidationException::class);

test('TrafficLightsContext throws MachineContextValidationException for invalid data', function (): void {
    TrafficLightsContext::validateAndCreate(['count' => -1]);
})->throws(MachineContextValidationException::class);

it('has magic methods', function (): void {
    $machine = AbcMachine::create();

    $machine->state->context->set('key', 'value1');
    expect($machine->state->context->key)->toBe('value1');

    $machine->state->context->key = 'value2';
    expect($machine->state->context->key)->toBe('value2');

    expect(isset($machine->state->context->key))->toBe(true);
    expect(isset($machine->state->context->not_existing_key))->toBe(false);
});

it('can check existence by its type', function (): void {
    $machine = AbcMachine::create();

    $machine->state->context->set('stringKey', 'stringValue');
    expect($machine->state->context->has(key: 'stringKey', type: 'string'))->toBe(true);

    $machine->state->context->set('intKey', 1);
    expect($machine->state->context->has(key: 'intKey', type: 'int'))->toBe(true);

    $machine->state->context->set('arrayKey', []);
    expect($machine->state->context->has(key: 'arrayKey', type: 'array'))->toBe(true);

    $machine->state->context->set('objectKey', new MachineEvent());
    expect($machine->state->context->has(key: 'objectKey', type: MachineEvent::class))->toBe(true);
});

// --- Cast System Tests ---

it('auto-detects Model serialization and deserialization', function (): void {
    $model = ModelA::create(['value' => 'test-model']);

    $context = TrafficLightsContext::from([
        'count'  => 5,
        'modelA' => $model,
    ]);

    $array = $context->toArray();

    expect($array['modelA'])->toBe($model->getKey());

    $restored = TrafficLightsContext::from($array);

    expect($restored->modelA)->toBeInstanceOf(ModelA::class);
    expect($restored->modelA->getKey())->toBe($model->getKey());
});

it('supports global cast registry via registerCast and flushState', function (): void {
    ContextManager::registerCast(MoneyValue::class, MoneyValueCast::class);

    $context = PaymentContext::from([
        'amount' => 1500,
    ]);

    // Deserialize: int → MoneyValue via global cast
    expect($context->amount)->toBeInstanceOf(MoneyValue::class);
    expect($context->amount->cents)->toBe(1500);

    // Serialize: MoneyValue → int via global cast
    $array = $context->toArray();
    expect($array['amount'])->toBe(1500);

    ContextManager::flushState();
});

it('supports explicit casts with array syntax for Collection of DTOs', function (): void {
    $context = OrderContext::from([
        'items' => [
            ['name' => 'Widget', 'price' => 100],
            ['name' => 'Gadget', 'price' => 200],
        ],
    ]);

    // Deserialized items should be a Collection of LineItemDto
    expect($context->items)->toBeInstanceOf(Collection::class);
    expect($context->items)->toHaveCount(2);
    expect($context->items->first())->toBeInstanceOf(LineItemDto::class);
    expect($context->items->first()->name)->toBe('Widget');
    expect($context->items->last()->price)->toBe(200);

    // Serialized form should be plain arrays
    $array = $context->toArray();
    expect($array['items'])->toBeArray();
    expect($array['items'][0])->toBe(['name' => 'Widget', 'price' => 100]);
    expect($array['items'][1])->toBe(['name' => 'Gadget', 'price' => 200]);
});

it('throws InvalidArgumentException when cast class does not implement ContextCast', function (): void {
    $context = new InvalidCastContext(value: 'test');
    $context->toArray();
})->throws(InvalidArgumentException::class);

it('throws MachineContextValidationException when selfValidate fails', function (): void {
    $context = TrafficLightsContext::from([
        'count' => -1,
    ]);

    $context->selfValidate();
})->throws(MachineContextValidationException::class);

// NOTE: Legacy format unwrap in restoreContext (where context data was stored
// under a nested 'context' key) is difficult to test in isolation because
// restoreContext is an internal engine method that operates within Machine::restore().
// This remains a test coverage gap.
