# Endpoints Feature — Implementation Plan

> **Son güncelleme:** Plan review + backend gap analizi sonrası revize edildi.

## Overview

EventMachine'e HTTP endpoint tanımlama yeteneği eklenmesi. Machine definition içinde event'ler için endpoint tanımlanır, `MachineRouter` aracılığıyla Laravel route'ları otomatik oluşturulur.

## Design Principles

1. **Endpoint tanımı machine definition'ın parçası** — `MachineDefinition::define()` dördüncü parametresi
2. **Varsayılan response State döner** — `State::$value` (array, paralel state'leri destekler) + context + machine_id
3. **Response özelleştirme ResultBehavior ile** — mevcut behavior sistemi yeniden kullanılır
4. **Lifecycle hook'ları EndpointAction ile** — before/after/onException, HTTP katmanında çalışır
5. **Model concern'ü Router'da kalır** — endpoint tanımı model'den habersiz

## Scope

### Kapsam içi
- Model-bound endpoint'ler (model → machine → send event)
- MachineId-bound endpoint'ler (pre-model phase)
- Stateless endpoint'ler (fresh machine → send → result → GC)
- Create endpoint (machine bootstrapping)
- EndpointAction (before/after/onException lifecycle)
- EndpointResult (response özelleştirme via ResultBehavior)
- Per-event middleware
- URI auto-generation

### Kapsam dışı (bilinçli karar)
- **Cross-machine endpoint'ler** (ConversionMachine gibi ayrı machine'e event gönderme) — bunlar consuming app'te kalır
- **Admin generic transition** (dinamik event type) — endpoint feature'ın amacı her event'e statik endpoint, dinamik routing değil
- **Dual-path migration** (TarfinPro `has_machine` check) — geçici migration pattern, endpoint feature sadece machine path'i kapsar

---

## Phase 1: Core Infrastructure

### 1.1 `State::toArray()` — State Serialization

**File:** `src/Actor/State.php`

State'in JSON serializable olması gerekiyor. Paralel state'leri doğru yansıtmalı.

```php
use JsonSerializable;

class State implements JsonSerializable
{
    // ...mevcut kod...

    public function toArray(): array
    {
        return [
            'value'   => $this->value,   // ['farmer_saved'] veya ['payment.pending', 'shipping.preparing']
            'context' => $this->context->toArray(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
```

---

### 1.2 `EndpointDefinition` — Value Object

**File:** `src/Routing/EndpointDefinition.php` (yeni)

Her endpoint tanımını normalize eden value object:

```php
class EndpointDefinition
{
    public function __construct(
        public readonly string  $eventType,       // 'FARMER_SAVED'
        public readonly string  $uri,             // '/farmer-saved'
        public readonly string  $method,          // 'POST' (varsayılan)
        public readonly ?string $actionClass,     // CancelEndpointAction::class
        public readonly ?string $resultBehavior,  // 'guarantorSavedEndpointResult' veya FQCN
        public readonly array   $middleware,      // ['auth:admin']
        public readonly ?int    $statusCode,      // null = varsayılan (200)
    ) {}

    /**
     * Endpoint tanımını normalize eder.
     *
     * Desteklenen formatlar:
     *   'FARMER_SAVED' => '/farmer'                        (string shorthand)
     *   'FARMER_SAVED' => ['uri' => '/farmer', ...]        (array)
     *   FarmerSavedEvent::class => '/farmer'                (event class key)
     *   'FARMER_SAVED' => null                              (URI auto-generated)
     *   'FARMER_SAVED' => []                                (URI auto-generated, array config)
     */
    public static function fromConfig(string $key, string|array|null $config, ?array $behavior): self
    {
        // Event class key → resolve type via EventBehavior::getType()
        // NOT: EventBehavior::getType() SCREAMING_SNAKE_CASE döndürür
        //      InvokableBehavior::getType() class basename döndürür — FARKLI
        $eventType = is_subclass_of($key, EventBehavior::class)
            ? (new $key)->getType()  // instance gerekli çünkü getType() non-static olabilir
            : $key;

        // String shorthand: 'EVENT' => '/uri'
        if (is_string($config)) {
            return new self(
                eventType:      $eventType,
                uri:            $config,
                method:         'POST',
                actionClass:    null,
                resultBehavior: null,
                middleware:     [],
                statusCode:     null,
            );
        }

        // Null veya array: auto-generate URI if not provided
        // FARMER_SAVED → /farmer-saved
        $config = $config ?? [];
        $uri    = $config['uri'] ?? self::generateUri($eventType);

        return new self(
            eventType:      $eventType,
            uri:            $uri,
            method:         $config['method'] ?? 'POST',
            actionClass:    $config['action'] ?? null,
            resultBehavior: $config['result'] ?? null,
            middleware:     $config['middleware'] ?? [],
            statusCode:     $config['status'] ?? null,
        );
    }

    /**
     * Event type'dan URI üretir.
     * FARMER_SAVED → /farmer-saved
     * APPROVED_WITH_INITIATIVE → /approved-with-initiative
     */
    public static function generateUri(string $eventType): string
    {
        return '/'.str_replace('_', '-', strtolower($eventType));
    }
}
```

---

### 1.3 `MachineEndpointAction` — Abstract Base

**File:** `src/Routing/MachineEndpointAction.php` (yeni)

HTTP lifecycle hook'ları. Machine internals'den tamamen ayrı — MachineController içinde çalışır.

```php
abstract class MachineEndpointAction
{
    protected Machine $machine;
    protected State   $state;

    /**
     * Machine ve state'i set eder (framework tarafından çağrılır).
     */
    public function withMachineContext(Machine $machine, State $state): self
    {
        $this->machine = $machine;
        $this->state   = $state;

        return $this;
    }

    /**
     * $machine->send() ÖNCESINDE çalışır.
     *
     * Erişim:
     *   $this->machine : Machine (yüklü)
     *   $this->state   : State   (geçiş ÖNCESİ durum)
     *
     * Kullanım: cache lock, authorization, pre-send validation.
     * abort() ile isteği durdurabilir.
     */
    public function before(): void {}

    /**
     * $machine->send() SONRASINDA çalışır.
     *
     * Erişim:
     *   $this->machine : Machine (geçiş tamamlanmış)
     *   $this->state   : State   (geçiş SONRASI yeni durum)
     *
     * Kullanım: lock release, post-transition side effects.
     */
    public function after(): void {}

    /**
     * $machine->send() sırasında exception fırlarsa çalışır.
     *
     * Exception'ı handle edip yeni bir response dönebilir,
     * veya null dönerse exception re-throw edilir.
     *
     * Kullanım: PreventionException → saveLog() + re-throw,
     *           lock release on failure.
     */
    public function onException(\Throwable $e): ?\Illuminate\Http\JsonResponse
    {
        return null; // default: re-throw
    }
}
```

**Before/After/OnException çalışma noktası:**

```
HTTP Request
│
├─ Route middleware
├─ Model binding / Machine loading
├─ Event resolution + validation (Spatie Data)
│
├─ ═══ action.before() ═══                  ← MachineController içinde
│       $this->state = geçiş ÖNCESİ durum
│
├─ try {
│      $machine->send($event)               ← Machine katmanı
│          Guards → Entry/Exit Actions → Context changes → State transition
│  }
│
├─ catch (Throwable $e) {
│      ═══ action.onException($e) ═══       ← MachineController içinde
│          null dönerse → exception yeniden fırlatılır
│          JsonResponse dönerse → o response kullanılır
│  }
│
├─ ═══ action.after() ═══                   ← MachineController içinde
│       $this->state = geçiş SONRASI yeni durum
│
├─ ResultBehavior (tanımlıysa) veya State::toArray()
│
└─ JSON Response
```

---

### 1.4 `MachineController` — Generic Controller

**File:** `src/Routing/MachineController.php` (yeni)

Tüm machine endpoint request'lerini handle eden tek controller.

**Kritik teknik notlar (plan review'dan):**
- `EventBehavior` Spatie Laravel Data'dan extend eder → `app()` ile resolve edilemez
- Event resolution: `$eventClass::validateAndCreate($request->all())` kullanılmalı
- `Machine::send()` `State` döndürür (void değil)
- `MachineValidationException` yakalanıp HTTP 422'ye dönüştürülmeli
- `MachineAlreadyRunningException` yakalanıp HTTP 409'a dönüştürülmeli
- ResultBehavior invoke: `$resultBehavior($state->context, $state->currentEventBehavior, $arguments)`

```php
class MachineController extends Controller
{
    /**
     * Model-bound endpoint handler.
     * Route: POST /{model}/{uri}
     */
    public function handleModelBound(Request $request): JsonResponse
    {
        // Route defaults'tan config oku
        $route          = $request->route();
        $machineClass   = $route->defaults['_machine_class'];
        $eventType      = $route->defaults['_event_type'];
        $modelAttribute = $route->defaults['_model_attribute'];
        $actionClass    = $route->defaults['_action_class'] ?? null;
        $resultKey      = $route->defaults['_result_behavior'] ?? null;
        $statusCode     = $route->defaults['_status_code'] ?? 200;

        // 1. Model route binding'den gelir
        $modelParam = $route->parameterNames()[0]; // İlk parametre model
        $model      = $route->parameter($modelParam);

        // 2. Machine'i model'den yükle
        $machine = $model->{$modelAttribute};

        // 3. Event resolution — Spatie Data pattern
        $eventClass = $machine->definition->behavior['events'][$eventType]
            ?? throw new \RuntimeException("Event type '{$eventType}' not found in behavior.");
        $event = $eventClass::validateAndCreate($request->all());

        return $this->executeEndpoint($machine, $event, $actionClass, $resultKey, $statusCode);
    }

    /**
     * MachineId-bound endpoint handler.
     * Route: POST /{machineId}/{uri}
     */
    public function handleMachineIdBound(Request $request): JsonResponse
    {
        $route        = $request->route();
        $machineClass = $route->defaults['_machine_class'];
        $eventType    = $route->defaults['_event_type'];
        $actionClass  = $route->defaults['_action_class'] ?? null;
        $resultKey    = $route->defaults['_result_behavior'] ?? null;
        $statusCode   = $route->defaults['_status_code'] ?? 200;

        $machineId = $route->parameter('machineId');

        // Machine'i root_event_id ile yükle
        $machine = $machineClass::create(state: $machineId);

        // Event resolution
        $eventClass = $machine->definition->behavior['events'][$eventType]
            ?? throw new \RuntimeException("Event type '{$eventType}' not found in behavior.");
        $event = $eventClass::validateAndCreate($request->all());

        return $this->executeEndpoint($machine, $event, $actionClass, $resultKey, $statusCode);
    }

    /**
     * Stateless endpoint handler.
     * Route: POST /{uri} (model yok, machineId yok, persist yok)
     *
     * Her request'te fresh machine oluşturulur, event gönderilir,
     * response döner ve machine garbage collect edilir.
     */
    public function handleStateless(Request $request): JsonResponse
    {
        $route        = $request->route();
        $machineClass = $route->defaults['_machine_class'];
        $eventType    = $route->defaults['_event_type'];
        $actionClass  = $route->defaults['_action_class'] ?? null;
        $resultKey    = $route->defaults['_result_behavior'] ?? null;
        $statusCode   = $route->defaults['_status_code'] ?? 200;

        // Fresh machine — persist yok, model yok
        $machine = $machineClass::create();

        // Event resolution
        $eventClass = $machine->definition->behavior['events'][$eventType]
            ?? throw new \RuntimeException("Event type '{$eventType}' not found in behavior.");
        $event = $eventClass::validateAndCreate($request->all());

        return $this->executeEndpoint($machine, $event, $actionClass, $resultKey, $statusCode);
    }

    /**
     * Create endpoint handler.
     * Route: POST /create
     */
    public function handleCreate(Request $request): JsonResponse
    {
        $machineClass = $request->route()->defaults['_machine_class'];

        $machine = $machineClass::create();
        $machine->persist();

        $rootEventId = $machine->state->history->first()?->root_event_id;

        return response()->json([
            'data' => [
                'machine_id' => $rootEventId,
                'value'      => $machine->state->value,
                'context'    => $machine->state->context->toArray(),
            ],
        ], 201);
    }

    /**
     * Ortak endpoint execution logic.
     */
    protected function executeEndpoint(
        Machine $machine,
        EventBehavior $event,
        ?string $actionClass,
        ?string $resultKey,
        int $statusCode,
    ): JsonResponse {
        // Action resolve (opsiyonel)
        $action = $actionClass
            ? app($actionClass)->withMachineContext($machine, $machine->state)
            : null;

        // Before hook
        $action?->before();

        // Send with exception handling
        try {
            $state = $machine->send(event: $event);
        } catch (MachineValidationException $e) {
            // Validation guard failure → HTTP 422
            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => method_exists($e, 'errors') ? $e->errors() : [],
            ], 422);
        } catch (\Throwable $e) {
            // Action'a exception handling şansı ver
            if ($action) {
                $action->withMachineContext($machine, $machine->state);
                $response = $action->onException($e);
                if ($response !== null) {
                    return $response;
                }
            }
            throw $e;
        }

        // After hook (state güncellendi)
        if ($action) {
            $action->withMachineContext($machine, $state);
        }
        $action?->after();

        // Response
        return $this->buildResponse($state, $machine, $resultKey, $statusCode);
    }

    /**
     * Response builder.
     */
    protected function buildResponse(
        State $state,
        Machine $machine,
        ?string $resultKey,
        int $statusCode,
    ): JsonResponse {
        // ResultBehavior tanımlıysa çalıştır
        if ($resultKey !== null) {
            $result = $this->resolveAndRunResult($resultKey, $state, $machine);

            return response()->json(['data' => $result], $statusCode);
        }

        // Varsayılan: State döner
        $rootEventId = $state->history->first()?->root_event_id;

        return response()->json([
            'data' => [
                'machine_id' => $rootEventId,
                'value'      => $state->value,
                'context'    => $state->context->toArray(),
            ],
        ], $statusCode);
    }

    /**
     * ResultBehavior'ı resolve edip çalıştırır.
     *
     * Machine::result() invoke convention'ını takip eder:
     * $resultBehavior($state->context, $state->currentEventBehavior, $arguments)
     */
    protected function resolveAndRunResult(
        string $resultKey,
        State $state,
        Machine $machine,
    ): mixed {
        // FQCN mi yoksa inline key mi?
        $resultClass = class_exists($resultKey)
            ? $resultKey
            : ($machine->definition->behavior['results'][$resultKey] ?? null);

        if ($resultClass === null) {
            throw new \RuntimeException("Result behavior '{$resultKey}' not found.");
        }

        // InvokableBehavior parameter injection pattern'ini kullan
        $resultBehavior = app($resultClass);

        $params = InvokableBehavior::injectInvokableBehaviorParameters(
            actionBehavior: $resultBehavior,
            state: $state,
            eventBehavior: $state->currentEventBehavior,
        );

        return $resultBehavior(...$params);
    }
}
```

---

### 1.5 `MachineRouter` — Route Registration

**File:** `src/Routing/MachineRouter.php` (yeni)

```php
class MachineRouter
{
    /**
     * Bir machine'in endpoint'lerini route olarak kayıt eder.
     *
     * @param  string  $machineClass    Machine alt sınıfı FQCN.
     *                                  Machine::definition() override etmeli.
     * @param  array   $options         Router configuration
     *
     * Options:
     *   'prefix'       => string       URL prefix (zorunlu)
     *   'model'        => string       Eloquent model class (opsiyonel)
     *   'attribute'    => string       HasMachines property adı (model ile birlikte)
     *   'create'       => bool         POST /create endpoint (default: false)
     *   'machineIdFor' => string[]     Model yerine machineId kullanan event type'lar
     *   'middleware'    => string[]     Tüm endpoint'lere uygulanan middleware
     *   'name'         => string       Route name prefix (default: machine id)
     */
    public static function register(string $machineClass, array $options): void
    {
        // Machine definition'ı al — Machine alt sınıfı definition() override etmeli
        $definition = $machineClass::definition();
        $endpoints  = $definition->parsedEndpoints ?? [];

        if ($endpoints === []) {
            return; // endpoint tanımlı değilse hiçbir şey yapma
        }

        $prefix       = $options['prefix'];
        $model        = $options['model'] ?? null;
        $attribute    = $options['attribute'] ?? null;
        $create       = $options['create'] ?? false;
        $machineIdFor = $options['machineIdFor'] ?? [];
        $middleware    = $options['middleware'] ?? [];
        $namePrefix   = $options['name'] ?? $definition->id;

        Route::prefix($prefix)
            ->middleware($middleware)
            ->group(function () use (
                $endpoints, $machineClass, $model, $attribute,
                $create, $machineIdFor, $namePrefix,
            ) {
                // Create endpoint
                if ($create) {
                    Route::post('/create', [MachineController::class, 'handleCreate'])
                        ->name("{$namePrefix}.create")
                        ->setDefaults(['_machine_class' => $machineClass]);
                }

                // Event endpoint'leri
                foreach ($endpoints as $eventType => $endpoint) {
                    $isMachineIdBound = in_array($eventType, $machineIdFor, true);

                    if ($model === null) {
                        // Stateless: model yok → fresh machine per request
                        $routeUri = $endpoint->uri;
                        $handler  = 'handleStateless';
                    } elseif ($isMachineIdBound) {
                        // Pre-model: machineId ile yükleme
                        $routeUri = "/{machineId}{$endpoint->uri}";
                        $handler  = 'handleMachineIdBound';
                    } else {
                        // Model-bound: model'den machine yükleme
                        $modelParam = Str::camel(class_basename($model));
                        $routeUri   = "/{{$modelParam}}{$endpoint->uri}";
                        $handler    = 'handleModelBound';
                    }

                    // Route name: FARMER_SAVED → farmer_saved
                    $routeName = "{$namePrefix}.".strtolower($eventType);

                    Route::match(
                        [$endpoint->method],
                        $routeUri,
                        [MachineController::class, $handler]
                    )
                        ->name($routeName)
                        ->middleware($endpoint->middleware)
                        ->setDefaults([
                            '_machine_class'   => $machineClass,
                            '_event_type'      => $eventType,
                            '_model_attribute' => $attribute,
                            '_action_class'    => $endpoint->actionClass,
                            '_result_behavior' => $endpoint->resultBehavior,
                            '_status_code'     => $endpoint->statusCode ?? 200,
                        ]);
                }
            });
    }
}
```

**Üretilen route örnekleri:**

```php
MachineRouter::register(ApplicationMachine::class, [
    'prefix'       => 'machines/application',
    'model'        => Application::class,
    'attribute'    => 'application_mre',
    'create'       => true,
    'machineIdFor' => ['START'],
    'middleware'    => ['auth:retailer'],
    'name'         => 'machines.application',
]);
```

| Method | URI | Handler | Route Name |
|--------|-----|---------|------------|
| POST | `/create` | handleCreate | `machines.application.create` |
| POST | `/{machineId}/start` | handleMachineIdBound | `machines.application.start` |
| POST | `/{application}/farmer-saved` | handleModelBound | `machines.application.farmer_saved` |
| POST | `/{application}/cancel` | handleModelBound | `machines.application.cancel` |
| POST | `/{application}/guarantor-saved` | handleModelBound | `machines.application.guarantor_saved` |
| PATCH | `/{application}/approved-with-initiative` | handleModelBound | `machines.application.approved_with_initiative` |

**machineIdFor ayrımı:** Router, `machineIdFor` array'indeki event type'ları kontrol eder. Listede olan event'ler `/{machineId}/uri` pattern'i, olmayanlar `/{model}/uri` pattern'i kullanır. Endpoint tanımı bundan habersiz.

**Stateless machine örneği:**

```php
MachineRouter::register(PriceCalculatorMachine::class, [
    'prefix'     => 'calculator',
    'middleware'  => ['auth:api'],
    // model yok → tüm endpoint'ler handleStateless kullanır
    // create yok → stateless machine'de bootstrapping gereksiz
]);
```

| Method | URI | Handler | Route Name |
|--------|-----|---------|------------|
| POST | `/calculator/calculate` | handleStateless | `price_calculator.calculate` |

**Üç handler tipi:**

| Handler | Model | MachineId | Ne zaman |
|---------|-------|-----------|----------|
| `handleStateless` | yok | yok | Router'da `model` tanımlı değil |
| `handleMachineIdBound` | yok | var | Event type `machineIdFor` listesinde |
| `handleModelBound` | var | - | Varsayılan (diğer tüm durumlar) |

---

## Phase 2: MachineDefinition Integration

### 2.1 `MachineDefinition::define()` — Yeni Parametre

**File:** `src/Definition/MachineDefinition.php`

```php
public static function define(
    ?array $config    = null,
    ?array $behavior  = null,
    ?array $scenarios = null,
    ?array $endpoints = null,   // ← yeni
): self {
    // ...mevcut logic...
}
```

Constructor'a `$endpoints` eklenir ve parse edilir:

```php
/**
 * @var array<string, EndpointDefinition>|null
 */
public ?array $parsedEndpoints = null;

private function __construct(
    public ?array $config,
    public ?array $behavior,
    public string $id,
    public ?string $version,
    public ?array $scenarios,
    public ?array $endpoints = null,  // ← yeni
    public string $delimiter = self::STATE_DELIMITER,
) {
    // ...mevcut logic...

    if ($this->endpoints !== null) {
        $this->parseEndpoints();
    }
}
```

### 2.2 Endpoint Parsing & Validation

```php
private function parseEndpoints(): void
{
    $this->parsedEndpoints = [];

    foreach ($this->endpoints as $key => $config) {
        $endpoint = EndpointDefinition::fromConfig($key, $config, $this->behavior);

        // Validation: endpoint event'i behavior events'te tanımlı mı?
        if (!isset($this->behavior['events'][$endpoint->eventType])) {
            throw InvalidEndpointDefinitionException::undefinedEvent($endpoint->eventType);
        }

        // Validation: result behavior tanımlıysa, resolve edilebilir mi?
        if ($endpoint->resultBehavior !== null
            && !class_exists($endpoint->resultBehavior)
            && !isset($this->behavior['results'][$endpoint->resultBehavior])
        ) {
            throw InvalidEndpointDefinitionException::undefinedResult($endpoint->resultBehavior);
        }

        // Validation: action class varsa, MachineEndpointAction extend ediyor mu?
        if ($endpoint->actionClass !== null
            && !is_subclass_of($endpoint->actionClass, MachineEndpointAction::class)
        ) {
            throw InvalidEndpointDefinitionException::invalidAction($endpoint->actionClass);
        }

        $this->parsedEndpoints[$endpoint->eventType] = $endpoint;
    }
}
```

### 2.3 `InvalidEndpointDefinitionException`

**File:** `src/Exceptions/InvalidEndpointDefinitionException.php` (yeni)

```php
class InvalidEndpointDefinitionException extends \RuntimeException
{
    public static function undefinedEvent(string $eventType): self { ... }
    public static function undefinedResult(string $resultKey): self { ... }
    public static function invalidAction(string $actionClass): self { ... }
}
```

---

## Phase 3: ServiceProvider & Package Integration

### 3.1 Dosya Yapısı

```
src/
├── Routing/                                        ← yeni dizin
│   ├── EndpointDefinition.php                      ← value object
│   ├── MachineController.php                       ← generic controller
│   ├── MachineEndpointAction.php                   ← abstract base
│   └── MachineRouter.php                           ← route registration
├── Exceptions/
│   └── InvalidEndpointDefinitionException.php      ← yeni
└── Actor/
    └── State.php                                   ← toArray() + JsonSerializable eklenir
```

### 3.2 MachineServiceProvider

Endpoint route'ları paket tarafından otomatik kayıt **edilmez**. Paket sadece mekanizmayı sağlar. Consuming app `MachineRouter::register()` çağrısını kendi route dosyasında yapar.

ServiceProvider'a ek bir şey gerekmez — tüm Routing sınıfları autoload ile kullanılabilir.

---

## Phase 4: Conventions & Documentation

### 4.1 conventions.md Güncellemesi

**File:** `docs/building/conventions.md`

Quick Reference tablosuna eklenecek satırlar:

| Element | Style | Pattern | Example |
|---------|-------|---------|---------|
| Endpoint action class | PascalCase | `{DescriptiveName}EndpointAction` | `CancelEndpointAction` |
| Endpoint action inline key | camelCase | `{descriptiveName}EndpointAction` | `cancelEndpointAction` |
| Endpoint result class | PascalCase | `{EventDerived}EndpointResult` | `GuarantorSavedEndpointResult` |
| Endpoint result inline key | camelCase | `{eventDerived}EndpointResult` | `guarantorSavedEndpointResult` |
| Endpoint URI (auto) | kebab-case | from event type | `/farmer-saved` |
| Route name (auto) | snake_case | from event type | `machines.application.farmer_saved` |

Yeni section eklenecek: **Endpoints** — action/result naming, URI convention, file organization.

File organization bölümüne `Endpoints/` dizini eklenecek:

```
app/MachineDefinitions/
└── OrderWorkflow/
    ├── OrderWorkflowMachine.php
    ├── OrderWorkflowContext.php
    ├── Actions/
    ├── Guards/
    ├── Events/
    ├── Results/
    └── Endpoints/
        ├── Actions/
        │   ├── CancelEndpointAction.php
        │   └── StartEndpointAction.php
        └── Results/
            └── OrderDetailEndpointResult.php
```

---

### 4.2 Vitepress Sidebar Güncellemesi

**File:** `docs/.vitepress/config.ts`

Laravel Integration sidebar grubuna (satır ~133 civarı) yeni entry eklenmeli:

```ts
{
  text: 'Laravel Integration',
  collapsed: true,
  items: [
    { text: 'Overview', link: '/laravel-integration/overview' },
    { text: 'Eloquent Integration', link: '/laravel-integration/eloquent-integration' },
    { text: 'Persistence', link: '/laravel-integration/persistence' },
    { text: 'Endpoints', link: '/laravel-integration/endpoints' },  // ← yeni
    { text: 'Archival', link: '/laravel-integration/archival' },
    { text: 'Compression', link: '/laravel-integration/compression' },
    { text: 'Artisan Commands', link: '/laravel-integration/artisan-commands' }
  ]
},
```

> Endpoints, Persistence'tan sonra gelir çünkü endpoint'ler persist edilen makinelerin üzerine inşa edilir.

---

### 4.3 Homepage Feature Section

**File:** `docs/index.md`

Mevcut 7 feature section'ın arasına (Archive bölümünden önce, Laravel Native bölümünden sonra) yeni bir feature section eklenecek. Mevcut `<div class="feature-section">` pattern'ini takip eder.

**İçerik:**

```md
<div class="feature-section">
<div class="feature-text">

## Zero-Boilerplate Endpoints

**Define endpoints in your machine, skip the controllers.** Each event becomes an HTTP endpoint automatically. One `MachineRouter::register()` call replaces dozens of routes and controllers.

Pre-send validation? Post-send cleanup? Exception handling? EndpointActions give you lifecycle hooks without touching machine internals.

[HTTP Endpoints &rarr;](/laravel-integration/endpoints)

</div>
<div class="feature-code">

<!-- doctest-attr: ignore -->
```php
MachineDefinition::define(
    config: [...],
    behavior: [...],
    endpoints: [
        'SUBMIT'  => null,              // POST /submit
        'APPROVE' => [
            'method'     => 'PATCH',
            'middleware'  => ['auth:admin'],
            'result'     => 'approvalResult',
        ],
        'CANCEL'  => [
            'action' => CancelEndpointAction::class,
        ],
    ],
);
```

<!-- doctest-attr: ignore -->
```php
// One call generates all routes
MachineRouter::register(OrderMachine::class, [
    'prefix'    => 'orders',
    'model'     => Order::class,
    'attribute' => 'order_mre',
    'create'    => true,
]);
// POST   /orders/create
// POST   /orders/{order}/submit
// PATCH  /orders/{order}/approve
// POST   /orders/{order}/cancel
```

</div>
</div>
```

> **Konum:** "Laravel Native" bölümünden hemen sonra, "Archive Millions" bölümünden hemen önce. Böylece "Laravel entegrasyonu → endpoint'ler → arşivleme" doğal sırası korunur.

---

### 4.4 Yeni Docs Sayfası — endpoints.md

**File:** `docs/laravel-integration/endpoints.md` (yeni)

Tam bir Vitepress dokümantasyon sayfası. Aşağıdaki bölüm yapısı ve her bölümde yer alacak detaylı içerik:

#### Bölüm Yapısı

```
# HTTP Endpoints

## Why Endpoints?
   - Boilerplate problem: controller + route per event
   - Endpoint solution: machine definition becomes single source of truth
   - Kod karşılaştırması (before/after)

## Defining Endpoints
   - endpoints parametresi MachineDefinition::define() 4. parametre
   - Dört format: null, string, array, event class key
   - Array configuration options tablosu (uri, method, action, result, middleware, status)
   - URI auto-generation kuralları ve tablo (SCREAMING_SNAKE → kebab-case)

## Route Registration
   - MachineRouter::register() çağrısı routes/api.php içinde
   - Router options tablosu (prefix, model, attribute, create, machineIdFor, middleware, name)
   - Üretilen route tablosu örneği

### Three Handler Types
   - handleModelBound: model tanımlı (varsayılan)
   - handleMachineIdBound: machineIdFor listesindeki event'ler
   - handleStateless: model tanımsız
   - Tablo ile karşılaştırma

## Default Response
   - State as JSON: machine_id + value + context
   - Paralel state response örneği (value array'de birden fazla active state)

## Custom Responses with ResultBehavior
   - Mevcut ResultBehavior altyapısının yeniden kullanımı
   - __invoke() parametreleri: ContextManager, State, EventCollection (DI injection)
   - İnline key referansı vs FQCN referansı
   - ::: tip Reusing ResultBehavior :::

## EndpointAction Lifecycle
   - before() → send() → after() timeline diagramı (ASCII art)
   - before(): validation, authorization, lock acquire
   - after(): lock release, post-transition side effects
   - onException(): null = re-throw, JsonResponse = handle
   - $this->machine ve $this->state erişimi
   - Cache lock örneği (tam kod)

## Create Endpoint
   - create: true ile POST /prefix/create
   - Response: 201 + machine_id + value + context
   - Kullanım: dönen machine_id ile sonraki request'lerde makineye event gönderme

## Pre-Model Events (machineIdFor)
   - Model henüz yokken event gönderme senaryosu
   - machineIdFor array'i ile seçici routing
   - Route pattern: /{machineId}/uri

## Stateless Endpoints
   - should_persist: false olan makineler
   - model ve create gereksiz
   - Her request: fresh machine → send → result → GC
   - Örnek: PriceCalculatorMachine

## Per-Event Middleware
   - Endpoint-level middleware: additive (router middleware üstüne eklenir)
   - Örnek: admin-only approve endpoint

## Exception Handling
   - MachineValidationException → 422 (otomatik)
   - Diğer exception'lar → EndpointAction.onException() veya re-throw
   - Tablo: exception → HTTP status → ne zaman

## File Organization
   - Önerilen dizin yapısı (Endpoints/Actions/ + Endpoints/Results/)

## Complete Example
   - Tam machine definition + route registration + action + result
   - Üretilen route tablosu

## Migration Guide
   - 6 adım: identify → add endpoint → move pre/post logic → move response → register → remove old
```

#### Kod Bloklarına Uygulanacak DocTest Attribute'ları

Her PHP kod bloğu için aşağıdaki kararlar uygulanacak:

| Blok İçeriği | Attribute | Gerekçe |
|-------------|-----------|---------|
| Motivasyon bölümündeki controller örnekleri | `ignore` | Tamamlanmamış snippet |
| `MachineDefinition::define(...)` overview örneği | `ignore` | `[...]` placeholder var |
| Endpoint definition format örnekleri | `no_run` | Package class dependency |
| `MachineRouter::register()` | `no_run` | Framework + package dependency |
| `ResultBehavior` alt sınıf tanımı | `no_run` | Package class dependency |
| `MachineEndpointAction` alt sınıf tanımı | `no_run` | Package class dependency |
| JSON response örnekleri | `ignore` | JSON, PHP değil |
| Create endpoint response | `ignore` | JSON |
| Stateless machine tam örnek | `no_run` | Package + framework dependency |
| Complete example | `no_run` | Package + framework dependency |
| `MachineRouter::register()` tek başına | `no_run` | Framework dependency |
| Bash/route tabloları | attribute yok | Zaten PHP değil |

> **Kural:** Bu sayfadaki hiçbir kod bloğu executable olmaz. Tamamı ya `no_run` (tam PHP class tanımı) ya `ignore` (incomplete snippet veya JSON). DocTest validation'da 0 Failed hedeflenir.

---

## Phase 5: Testing

### 5.1 Unit Tests

- `EndpointDefinitionTest`
  - String shorthand parsing
  - Array config parsing
  - Event class key resolution
  - Null config → URI auto-generation
  - `generateUri()` — SCREAMING_SNAKE → kebab-case
- `MachineEndpointActionTest` — before/after/onException lifecycle, state access
- `StateToArrayTest` — serialization, parallel states, empty state

### 5.2 Integration Tests

- `MachineRouterTest`
  - Route registration (model-bound, machineId-bound, create)
  - Route naming
  - Middleware application
  - machineIdFor ayrımı
  - Stateless routing (model yok → handleStateless)
- `MachineControllerTest` — full HTTP request lifecycle
  - Simple endpoint → State JSON response
  - Stateless endpoint → fresh machine per request
  - Endpoint with ResultBehavior → custom response
  - Endpoint with EndpointAction before/after
  - Endpoint with action + result
  - Create endpoint → 201 + machine_id
  - machineIdFor endpoint → machineId ile yükleme
  - Per-event middleware
  - MachineValidationException → HTTP 422
  - EndpointAction.onException() → custom error response
  - Invalid/unknown event type → hata

### 5.3 Test Stubs

- `TestEndpointMachine` — basit machine with endpoints
- `TestEndpointAction` — before/after/onException test action
- `TestEndpointResult` — custom ResultBehavior

---

## Backend Uyumluluk Matrisi

Backend projesindeki her controller pattern'i için plan uyumluluğu:

| Pattern | Metod Sayısı | Plan Karşılığı | Durum |
|---------|-------------|----------------|-------|
| Saf send + Resource | ~25 | `'EVENT' => null` + EndpointResult | ✅ |
| Cache lock (start) | 3 | EndpointAction before/after | ✅ |
| Pre-send validation (cancel) | 2 | EndpointAction before | ✅ |
| Custom eager loading | 5 | EndpointResult (ResultBehavior) | ✅ |
| Per-event auth | 3 | endpoint middleware | ✅ |
| PreventionException handling | 2 | EndpointAction onException | ✅ |
| HTTP 201 (create) | 2 | create endpoint → 201 varsayılan | ✅ |
| Stateless machine (PriceCalculator) | 4 | handleStateless + EndpointResult | ✅ |
| Cross-machine (ConversionMachine) | 3 | Kapsam dışı — consuming app'te kalır | ⚪ |
| Dynamic event (admin transition) | 1 | Kapsam dışı — statik endpoint değil | ⚪ |
| Dual-path (has_machine) | 8 | Kapsam dışı — migration pattern | ⚪ |
| Machine-less (pay3D etc.) | 5 | Kapsam dışı — machine kullanmıyor | ⚪ |

✅ = Tam kapsanıyor | ⚪ = Bilinçli kapsam dışı

---

## Tam Kullanım Örneği

### Machine Definition

```php
class ApplicationMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'application',
                'initial' => 'idle',
                'context' => ['application' => null],
                'states'  => [
                    'idle'            => ['on' => ['START' => 'started']],
                    'started'         => ['on' => ['FARMER_SAVED' => 'farmer_saved']],
                    'farmer_saved'    => ['on' => [
                        'CANCEL'          => 'cancelled',
                        'GUARANTOR_SAVED' => 'guarantor_saved',
                    ]],
                    'guarantor_saved' => ['on' => ['APPROVED_WITH_INITIATIVE' => 'approved']],
                    'approved'        => ['type' => 'final'],
                    'cancelled'       => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'START'                    => ApplicationStartedEvent::class,
                    'FARMER_SAVED'             => FarmerSavedEvent::class,
                    'CANCEL'                   => ApplicationCancelEvent::class,
                    'GUARANTOR_SAVED'          => GuarantorSavedEvent::class,
                    'APPROVED_WITH_INITIATIVE' => ApprovedWithInitiativeEvent::class,
                ],
                'results' => [
                    'guarantorSavedEndpointResult'        => GuarantorSavedEndpointResult::class,
                    'approvedWithInitiativeEndpointResult' => ApprovedWithInitiativeEndpointResult::class,
                ],
            ],
            endpoints: [
                // URI auto-generated: /start
                'START' => [
                    'action' => StartEndpointAction::class,
                ],

                // URI auto-generated: /farmer-saved
                'FARMER_SAVED' => null,

                // Event class key + action
                ApplicationCancelEvent::class => [
                    'action' => CancelEndpointAction::class,
                ],

                // Custom result
                'GUARANTOR_SAVED' => [
                    'result' => 'guarantorSavedEndpointResult',
                ],

                // Full config
                'APPROVED_WITH_INITIATIVE' => [
                    'method'     => 'PATCH',
                    'middleware' => ['auth:admin'],
                    'result'     => 'approvedWithInitiativeEndpointResult',
                ],
            ],
        );
    }
}
```

### Route Registration

```php
// routes/api.machines.php
MachineRouter::register(ApplicationMachine::class, [
    'prefix'       => 'machines/application',
    'model'        => Application::class,
    'attribute'    => 'application_mre',
    'create'       => true,
    'machineIdFor' => ['START'],
    'middleware'    => ['auth:retailer'],
    'name'         => 'machines.application',
]);
```

### EndpointAction Örnekleri

```php
class StartEndpointAction extends MachineEndpointAction
{
    private Lock $lock;

    public function before(): void
    {
        $nin = request()->input('nin');
        $this->lock = Cache::lock("farmer:{$nin}", 10);
        abort_unless($this->lock->block(5), 409);
    }

    public function after(): void
    {
        $this->lock->release();
    }

    public function onException(\Throwable $e): ?JsonResponse
    {
        $this->lock?->release();

        if ($e instanceof PreventionException) {
            $e->saveLog();
        }

        return null; // re-throw
    }
}

class CancelEndpointAction extends MachineEndpointAction
{
    public function before(): void
    {
        $application = $this->state->context->application;

        abort_unless(
            in_array($application->status, ApplicationStatus::cancellableStatuses()),
            422,
            'Application cannot be cancelled in current state.',
        );
    }
}
```

### EndpointResult Örneği

```php
class GuarantorSavedEndpointResult extends ResultBehavior
{
    public function __invoke(ContextManager $context): ApplicationResource
    {
        return new ApplicationResource(
            $context->application->refresh()->loadMissing('guarantors')
        );
    }
}
```

### Stateless Machine Örneği

```php
class PriceCalculatorMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'price_calculator',
                'initial'        => 'idle',
                'should_persist' => false,
                'states'         => [
                    'idle'       => ['on' => ['CALCULATE' => 'calculated']],
                    'calculated' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'CALCULATE' => CalculateEvent::class,
                ],
                'actions' => [
                    'calculatePricesAction' => CalculatePricesAction::class,
                ],
                'results' => [
                    'priceEndpointResult' => PriceEndpointResult::class,
                ],
            ],
            endpoints: [
                'CALCULATE' => [
                    'result' => 'priceEndpointResult',
                ],
            ],
        );
    }
}

// Route registration — model yok, create yok
MachineRouter::register(PriceCalculatorMachine::class, [
    'prefix'     => 'calculator',
    'middleware'  => ['auth:api'],
]);

// POST /calculator/calculate → fresh machine → send → result → GC
```

### Varsayılan Response (State)

```json
{
    "data": {
        "machine_id": "01JARX5Z8KQVN...",
        "value": ["application.farmer_saved"],
        "context": {
            "application_id": 42,
            "farmer_nin": "12345678901"
        }
    }
}
```

### Paralel State Response

```json
{
    "data": {
        "machine_id": "01JARX5Z8KQVN...",
        "value": [
            "fulfillment.payment.pending",
            "fulfillment.shipping.preparing",
            "fulfillment.documents.awaiting"
        ],
        "context": {}
    }
}
```

---

## Implementation Order

1. `State::toArray()` + `JsonSerializable` — bağımsız, mevcut testleri bozmaz
2. `EndpointDefinition` value object — bağımsız
3. `MachineEndpointAction` abstract class — bağımsız
4. `InvalidEndpointDefinitionException` — bağımsız
5. `MachineDefinition::define()` — `endpoints` parametresi + parsing
6. `MachineController` — generic controller
7. `MachineRouter` — route registration
8. Test stubs (TestEndpointMachine, etc.)
9. Unit + integration tests
10. `conventions.md` güncellemesi (4.1)
11. `docs/.vitepress/config.ts` sidebar entry (4.2)
12. `docs/index.md` homepage feature section (4.3)
13. `docs/laravel-integration/endpoints.md` tam sayfa (4.4)
14. DocTest attribute'ları uygulama + validation

> **Not:** 1-4 arası birbirinden bağımsız — paralel implement edilebilir.
> 5, 6, 7 sıralı — her biri bir öncekine bağımlı.
> 8-9 test aşaması.
> 10-14 dokümantasyon aşaması — 11, 12, 13 paralel yapılabilir, 14 son adım.
