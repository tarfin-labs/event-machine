<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Illuminate\Validation\ValidationException;
use Tarfinlabs\EventMachine\Testing\HasBuilder;
use Tarfinlabs\EventMachine\Testing\EventBuilder;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\SimpleEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\ValidatedEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Builders\SimpleEventBuilder;
use Tarfinlabs\EventMachine\Tests\Stubs\Builders\MinimalEventBuilder;
use Tarfinlabs\EventMachine\Tests\Stubs\Builders\ValidatedEventBuilder;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext;

// ═══════════════════════════ HasBuilder: Event::builder() ═══════════════════

it('resolves builder via Event::builder()', function (): void {
    $builder = SimpleEvent::builder();

    expect($builder)->toBeInstanceOf(SimpleEventBuilder::class);
    expect($builder)->toBeInstanceOf(EventBuilder::class);
});

it('Event::builder() produces same result as Builder::new()', function (): void {
    $viaEvent   = SimpleEvent::builder()->withValue(42)->make();
    $viaBuilder = SimpleEventBuilder::new()->withValue(42)->make();

    expect($viaEvent->payload['value'])->toBe(42);
    expect($viaBuilder->payload['value'])->toBe(42);
    expect($viaEvent->type)->toBe($viaBuilder->type);
});

it('resolves validated event builder via Event::builder()', function (): void {
    $builder = ValidatedEvent::builder();

    expect($builder)->toBeInstanceOf(ValidatedEventBuilder::class);
});

it('throws when builder class not found', function (): void {
    $event = new class() extends EventBehavior {
        use HasBuilder;

        public static function getType(): string
        {
            return 'NO_BUILDER_EVENT';
        }
    };

    expect(fn () => $event::builder())->toThrow(RuntimeException::class);
});

// ═══════════════════════════ Core: ::new() + make() ═══════════════════════════

it('creates event with definition defaults', function (): void {
    $event = SimpleEventBuilder::new()->make();

    expect($event)->toBeInstanceOf(SimpleEvent::class);
    expect($event->type)->toBe('SIMPLE_EVENT');
    expect($event->version)->toBe(1);
    expect($event->payload)->toHaveKeys(['name', 'value']);
    expect($event->payload['name'])->toBeString();
    expect($event->payload['value'])->toBeInt();
});

// ─── make() inline overrides ────────────────────────────

it('accepts inline overrides in make', function (): void {
    $event = SimpleEventBuilder::new()->make([
        'payload' => ['name' => 'override'],
    ]);

    expect($event->payload['name'])->toBe('override');
    expect($event->payload)->toHaveKey('value');
});

it('make override takes precedence over state', function (): void {
    $event = SimpleEventBuilder::new()
        ->withValue(42)
        ->make(['payload' => ['value' => 99]]);

    expect($event->payload['value'])->toBe(99);
});

// ═══════════════════════ State Mutations ═══════════════════════════

it('applies closure state mutation', function (): void {
    $event = SimpleEventBuilder::new()
        ->state(function (array $attrs) {
            $attrs['payload']['value'] = 42;

            return $attrs;
        })
        ->make();

    expect($event->payload['value'])->toBe(42);
});

it('applies array state mutation with deep merge', function (): void {
    $event = SimpleEventBuilder::new()
        ->state(['payload' => ['value' => 42]])
        ->make();

    expect($event->payload['value'])->toBe(42);
    expect($event->payload)->toHaveKey('name');
});

it('applies multiple states in declaration order — last wins', function (): void {
    $event = SimpleEventBuilder::new()
        ->state(['payload' => ['value' => 1]])
        ->state(['payload' => ['value' => 2]])
        ->state(['payload' => ['value' => 3]])
        ->make();

    expect($event->payload['value'])->toBe(3);
});

it('closure state sees accumulated result from previous states', function (): void {
    $event = SimpleEventBuilder::new()
        ->state(['payload' => ['value' => 10]])
        ->state(function (array $attrs) {
            $attrs['payload']['doubled'] = $attrs['payload']['value'] * 2;

            return $attrs;
        })
        ->make();

    expect($event->payload['value'])->toBe(10);
    expect($event->payload['doubled'])->toBe(20);
});

it('deep merges nested payload without losing sibling keys', function (): void {
    $event = SimpleEventBuilder::new()
        ->state(['payload' => ['extra' => 'added']])
        ->make();

    expect($event->payload)->toHaveKeys(['name', 'value', 'extra']);
    expect($event->payload['extra'])->toBe('added');
});

it('closure state replaces entire array — unreturned keys are lost', function (): void {
    $event = SimpleEventBuilder::new()
        ->state(function (array $attrs) {
            return [
                'type'    => $attrs['type'],
                'payload' => ['only_this' => true],
                'version' => $attrs['version'],
            ];
        })
        ->make();

    expect($event->payload)->toBe(['only_this' => true]);
});

// ═══════════════════════════ Immutability ═══════════════════════════

it('clones on state — original builder is not mutated', function (): void {
    $base  = SimpleEventBuilder::new();
    $withA = $base->state(['payload' => ['value' => 1]]);
    $withB = $base->state(['payload' => ['value' => 2]]);

    expect($withA->make()->payload['value'])->toBe(1);
    expect($withB->make()->payload['value'])->toBe(2);
});

it('allows branching from intermediate state', function (): void {
    $base = SimpleEventBuilder::new()->withName('base');

    $branchA = $base->withValue(10);
    $branchB = $base->withValue(20);

    $eventA = $branchA->make();
    $eventB = $branchB->make();

    expect($eventA->payload['name'])->toBe('base');
    expect($eventB->payload['name'])->toBe('base');
    expect($eventA->payload['value'])->toBe(10);
    expect($eventB->payload['value'])->toBe(20);
});

// ═══════════════════════════ raw() ═══════════════════════════

it('returns raw array without hydrating to EventBehavior', function (): void {
    $raw = SimpleEventBuilder::new()->raw();

    expect($raw)->toBeArray();
    expect($raw)->toHaveKeys(['type', 'payload', 'version']);
    expect($raw['type'])->toBe('SIMPLE_EVENT');
});

it('applies states to raw output', function (): void {
    $raw = SimpleEventBuilder::new()->withValue(42)->raw();

    expect($raw['payload']['value'])->toBe(42);
});

it('raw inline override takes precedence over state', function (): void {
    $raw = SimpleEventBuilder::new()
        ->withValue(42)
        ->raw(['payload' => ['value' => 99]]);

    expect($raw['payload']['value'])->toBe(99);
});

// ═══════════════════════ Custom Builder Methods ═══════════════════════

it('chains multiple custom builder methods', function (): void {
    $event = SimpleEventBuilder::new()
        ->withName('John')
        ->withValue(42)
        ->make();

    expect($event->payload['name'])->toBe('John');
    expect($event->payload['value'])->toBe(42);
});

// ═══════════════════ Minimal Builder (no definition override) ═══════════════

it('works with default definition — only eventClass required', function (): void {
    $event = MinimalEventBuilder::new()->make();

    expect($event)->toBeInstanceOf(SimpleEvent::class);
    expect($event->type)->toBe('SIMPLE_EVENT');
    expect($event->payload)->toBe([]);
    expect($event->version)->toBe(1);
});

it('minimal builder applies state on top of default definition', function (): void {
    $event = MinimalEventBuilder::new()->withValue(42)->make();

    expect($event->payload['value'])->toBe(42);
});

it('minimal builder raw returns default structure', function (): void {
    $raw = MinimalEventBuilder::new()->raw();

    expect($raw)->toBe([
        'type'    => 'SIMPLE_EVENT',
        'payload' => [],
        'version' => 1,
    ]);
});

// ═══════════════════ Validation Integration ═══════════════════════

it('builds valid event via validated builder', function (): void {
    $event = ValidatedEventBuilder::new()->make();

    expect($event)->toBeInstanceOf(ValidatedEvent::class);
    expect($event->payload['attribute'])->toBeInt()->toBeBetween(1, 10);
});

it('raw + validateAndCreate succeeds with valid defaults', function (): void {
    $raw   = ValidatedEventBuilder::new()->raw();
    $event = ValidatedEvent::validateAndCreate($raw);

    expect($event)->toBeInstanceOf(ValidatedEvent::class);
});

it('raw + validateAndCreate throws with invalid state', function (): void {
    $raw = ValidatedEventBuilder::new()->withInvalidAttribute()->raw();

    expect(fn () => ValidatedEvent::validateAndCreate($raw))
        ->toThrow(ValidationException::class);
});

// ═══════════════════ Integration with State::forTesting ═══════════════════

it('builder-created event is usable as currentEventBehavior', function (): void {
    $event = SimpleEventBuilder::new()->withValue(5)->make();

    $state = State::forTesting(
        new TrafficLightsContext(count: 10),
        currentEventBehavior: $event,
    );

    expect($state->currentEventBehavior)->toBeInstanceOf(SimpleEvent::class);
    expect($state->currentEventBehavior->payload['value'])->toBe(5);
});

// ═══════════════════ Edge Cases ═══════════════════════════════

it('version can be overridden via state', function (): void {
    $event = SimpleEventBuilder::new()->state(['version' => 2])->make();

    expect($event->version)->toBe(2);
});

it('type can be overridden via state', function (): void {
    $event = SimpleEventBuilder::new()->state(['type' => 'CUSTOM_TYPE'])->make();

    expect($event->type)->toBe('CUSTOM_TYPE');
});
