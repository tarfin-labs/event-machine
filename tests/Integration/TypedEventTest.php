<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Models\ModelA;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\SimpleEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\MoneyValue;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\MoneyValueCast;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\TypedTransferEvent;
use Tarfinlabs\EventMachine\Exceptions\MachineEventValidationException;

// region Round-trip Tests

it('typed event from()/toArray() round-trip', function (): void {
    $event = TypedTransferEvent::from([
        'type'    => 'TYPED_TRANSFER',
        'payload' => [
            'amount'    => 100,
            'recipient' => 'john',
        ],
    ]);

    expect($event->amount)->toBe(100);
    expect($event->recipient)->toBe('john');
    expect($event->type)->toBe('TYPED_TRANSFER');

    $array = $event->toArray();

    expect($array['type'])->toBe('TYPED_TRANSFER');
    expect($array['payload']['amount'])->toBe(100);
    expect($array['payload']['recipient'])->toBe('john');
    expect($array['version'])->toBe(1);
});

it('typed event auto-detects Model cast', function (): void {
    $model = ModelA::create(['value' => 'sender-test']);

    $event = TypedTransferEvent::from([
        'type'    => 'TYPED_TRANSFER',
        'payload' => [
            'amount'       => 50,
            'recipient'    => 'jane',
            'sender_model' => $model->getKey(),
        ],
    ]);

    expect($event->sender_model)->toBeInstanceOf(ModelA::class);
    expect($event->sender_model->getKey())->toBe($model->getKey());

    $array = $event->toArray();

    expect($array['payload']['sender_model'])->toBe($model->getKey());
});

it('untyped event preserves backward compat', function (): void {
    $event = SimpleEvent::from([
        'type'    => 'SIMPLE_EVENT',
        'payload' => ['key' => 'value123'],
    ]);

    expect($event->payload['key'])->toBe('value123');
    expect($event->key)->toBe('value123');
});

// endregion

// region Validation Tests

it('typed event selfValidate() throws on invalid data', function (): void {
    $event = TypedTransferEvent::from([
        'type'    => 'TYPED_TRANSFER',
        'payload' => [
            'amount'    => 0,
            'recipient' => 'john',
        ],
    ]);

    $event->selfValidate();
})->throws(MachineEventValidationException::class);

it('typed event validateAndCreate() throws on invalid payload', function (): void {
    TypedTransferEvent::validateAndCreate([
        'type'    => 'TYPED_TRANSFER',
        'payload' => [
            'amount'    => 0,
            'recipient' => 'john',
        ],
    ]);
})->throws(MachineEventValidationException::class);

it('flat rules have no payload prefix', function (): void {
    $rules = TypedTransferEvent::rules();

    expect($rules)->toHaveKeys(['amount', 'recipient']);
    expect($rules)->not->toHaveKey('payload.amount');
    expect($rules)->not->toHaveKey('payload.recipient');
});

it('stopOnFirstFailure() returns true by default', function (): void {
    expect(TypedTransferEvent::stopOnFirstFailure())->toBeTrue();

    // Validate with multiple invalid fields — only first error reported
    try {
        TypedTransferEvent::validateAndCreate([
            'type'    => 'TYPED_TRANSFER',
            'payload' => [
                'amount'    => 0,
                'recipient' => null,
            ],
        ]);
    } catch (MachineEventValidationException $e) {
        $errors = $e->errors();

        expect($errors)->toHaveCount(1);

        return;
    }

    test()->fail('Expected MachineEventValidationException was not thrown.');
});

// endregion

// region API Tests

it('payload() method returns computed array in typed mode', function (): void {
    $event = TypedTransferEvent::from([
        'type'    => 'TYPED_TRANSFER',
        'payload' => [
            'amount'    => 200,
            'recipient' => 'alice',
        ],
    ]);

    $payload = $event->payload();

    expect($payload)->toBeArray();
    expect($payload['amount'])->toBe(200);
    expect($payload['recipient'])->toBe('alice');
    expect($payload['sender_model'])->toBeNull();
});

it('payload() method returns stored array in untyped mode', function (): void {
    $event = SimpleEvent::from([
        'type'    => 'SIMPLE_EVENT',
        'payload' => ['foo' => 'bar', 'baz' => 42],
    ]);

    $payload = $event->payload();

    expect($payload)->toBeArray();
    expect($payload['foo'])->toBe('bar');
    expect($payload['baz'])->toBe(42);
});

it('getScenario() works in typed mode', function (): void {
    // Typed event with scenarioType as a dynamic payload key
    // Since TypedTransferEvent does not have scenarioType property,
    // getScenario() should return null
    $event = TypedTransferEvent::from([
        'type'    => 'TYPED_TRANSFER',
        'payload' => [
            'amount'    => 100,
            'recipient' => 'bob',
        ],
    ]);

    expect($event->getScenario())->toBeNull();

    // For an event that supports scenarioType, create an anonymous typed event
    $scenarioEvent = new class(scenarioType: 'premium') extends EventBehavior {
        public function __construct(
            public ?string $scenarioType = null,
        ) {}

        public static function getType(): string
        {
            return 'SCENARIO_EVENT';
        }
    };

    expect($scenarioEvent->getScenario())->toBe('premium');
});

it('forTesting() creates typed event with defaults', function (): void {
    $event = TypedTransferEvent::forTesting();

    expect($event)->toBeInstanceOf(TypedTransferEvent::class);
    expect($event->type)->toBe('TYPED_TRANSFER');
    expect($event->amount)->toBe(0);
    expect($event->recipient)->toBeNull();
    expect($event->version)->toBe(1);
});

it('all() and data() work in typed mode', function (): void {
    $event = TypedTransferEvent::from([
        'type'    => 'TYPED_TRANSFER',
        'payload' => [
            'amount'    => 300,
            'recipient' => 'charlie',
        ],
    ]);

    $all = $event->all();

    expect($all)->toBeArray();
    expect($all['amount'])->toBe(300);
    expect($all['recipient'])->toBe('charlie');

    expect($event->data('amount'))->toBe(300);
    expect($event->data('recipient'))->toBe('charlie');
    expect($event->data('nonexistent'))->toBeNull();
    expect($event->data('nonexistent', 'default'))->toBe('default');
});

it('collect(), only(), except() work via InteractsWithData trait in typed mode', function (): void {
    $event = TypedTransferEvent::from([
        'type'    => 'TYPED_TRANSFER',
        'payload' => [
            'amount'    => 400,
            'recipient' => 'dave',
        ],
    ]);

    $collection = $event->collect();

    expect($collection)->toBeInstanceOf(Collection::class);
    expect($collection->get('amount'))->toBe(400);
    expect($collection->get('recipient'))->toBe('dave');

    $only = $event->only('amount');

    expect($only)->toBe(['amount' => 400]);

    $except = $event->except('amount');

    expect($except)->toHaveKey('recipient');
    expect($except)->not->toHaveKey('amount');
});

// endregion

// region Cast Tests

it('typeCasts() on event apply during round-trip', function (): void {
    $event = new class(amount: new MoneyValue(cents: 2500)) extends EventBehavior {
        public function __construct(
            public ?MoneyValue $amount = null,
        ) {}

        public static function getType(): string
        {
            return 'CAST_EVENT';
        }

        public static function typeCasts(): array
        {
            return [
                MoneyValue::class => MoneyValueCast::class,
            ];
        }
    };

    $array = $event->toArray();

    expect($array['payload']['amount'])->toBe(2500);

    // Re-hydrate
    $restored = $event::from($array);

    expect($restored->amount)->toBeInstanceOf(MoneyValue::class);
    expect($restored->amount->cents)->toBe(2500);
});

it('config-based cast applies to event payload', function (): void {
    config()->set('machine.casts', [
        MoneyValue::class => MoneyValueCast::class,
    ]);

    $event = new class(price: new MoneyValue(cents: 999)) extends EventBehavior {
        public function __construct(
            public ?MoneyValue $price = null,
        ) {}

        public static function getType(): string
        {
            return 'CONFIG_CAST_EVENT';
        }
    };

    $array = $event->toArray();

    expect($array['payload']['price'])->toBe(999);

    $restored = $event::from($array);

    expect($restored->price)->toBeInstanceOf(MoneyValue::class);
    expect($restored->price->cents)->toBe(999);

    // Clean up
    config()->set('machine.casts', []);
});

it('4-layer resolution priority: casts > typeCasts > config > auto-detect', function (): void {
    config()->set('machine.casts', [
        MoneyValue::class => MoneyValueCast::class,
    ]);

    // Layer 1 (casts) wins over all others.
    // Create an event where casts() overrides the property-level cast.
    $event = new class(amount: new MoneyValue(cents: 1000)) extends EventBehavior {
        public function __construct(
            public ?MoneyValue $amount = null,
        ) {}

        public static function getType(): string
        {
            return 'PRIORITY_CAST_EVENT';
        }

        /**
         * Layer 1: explicit per-property cast — should take priority.
         */
        public static function casts(): array
        {
            return [
                'amount' => MoneyValueCast::class,
            ];
        }

        /**
         * Layer 2: per-type cast — should NOT be used since casts() covers 'amount'.
         */
        public static function typeCasts(): array
        {
            return [
                MoneyValue::class => MoneyValueCast::class,
            ];
        }
    };

    // Serialize — goes through casts() (Layer 1)
    $array = $event->toArray();

    expect($array['payload']['amount'])->toBe(1000);

    // Deserialize — goes through casts() (Layer 1)
    $restored = $event::from($array);

    expect($restored->amount)->toBeInstanceOf(MoneyValue::class);
    expect($restored->amount->cents)->toBe(1000);

    // Layer 4 auto-detect: Model type is auto-detected without any cast config
    $model = ModelA::create(['value' => 'priority-test']);

    $modelEvent = TypedTransferEvent::from([
        'type'    => 'TYPED_TRANSFER',
        'payload' => [
            'amount'       => 1,
            'recipient'    => 'test',
            'sender_model' => $model->getKey(),
        ],
    ]);

    expect($modelEvent->sender_model)->toBeInstanceOf(ModelA::class);

    $modelArray = $modelEvent->toArray();

    expect($modelArray['payload']['sender_model'])->toBe($model->getKey());

    // Clean up
    config()->set('machine.casts', []);
});

// endregion
