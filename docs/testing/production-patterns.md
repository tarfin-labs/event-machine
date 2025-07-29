# Production Testing Patterns

This guide explores real-world testing patterns used in large-scale EventMachine applications, based on analysis of production codebases with complex state machines containing hundreds of states and transitions.

## Large-Scale Machine Architecture

### Complex State Machine Organization

Production EventMachine applications often feature sophisticated hierarchical state machines with nested sub-states and complex routing logic.

#### Hierarchical State Structure

```php
'states' => [
    'findeks' => [
        'initial' => 'checking',
        'states' => [
            'checking' => [
                'entry' => [GetFindeksReportAction::class],
                'on' => [
                    FindeksValidationWaitingEvent::class => [
                        'target' => 'findeks.validationWaiting',
                        'actions' => [FindeksCheckingToValidationWaitingTransitionAction::class],
                    ],
                    FindeksReportSavedEvent::class => self::FINDEKS_REPORT_SAVED_TRANSITION,
                    FindeksPinWaitingEvent::class => [
                        'target' => 'findeks.pinWaiting',
                        'actions' => [FindeksCheckingToPinWaitingTransitionAction::class],
                    ],
                ],
            ],
            'validationWaiting' => [/* ... */],
            'validated' => [/* ... */],
            'pinWaiting' => [/* ... */],
            'pinConfirmed' => [/* ... */],
            'reportSaved' => [/* ... */],
        ],
    ],
    'protocol' => [
        'initial' => 'idle',
        'states' => [
            'idle' => [/* ... */],
            'checking' => [/* ... */],
            'undecided' => [/* ... */],
        ],
    ],
]
```

#### State Resolver Patterns

Production machines use resolver states with `@always` transitions for complex conditional routing:

```php
'idleStateResolver' => [
    'on' => [
        '@always' => [
            [
                'target' => 'farmer',
                'guards' => [IsNewFarmerGuard::class],
                'actions' => [FarmerInformationWaitingTransitionAction::class],
            ],
            [
                'target' => 'pro.cashSale.approved',
                'guards' => 'checkSalesChannel:'.SalesChannelType::DirectCash->value,
            ],
            [
                'target' => 'protocol',
                'guards' => [HasFindeksReportGuard::class],
                'actions' => [HandlePreviousFindeksReportAction::class],
            ],
            [
                'target' => 'findeks', // Default fallback
            ],
        ],
    ],
],
```

#### Reusable Transition Constants

Large machines define common transitions as constants to avoid duplication:

```php
public const array APPROVED_TRANSITION = [
    [
        'target' => 'approved.mobile',
        'guards' => [IsMobileApplicationGuard::class],
        'actions' => [
            CheckPotentialFraudIndicatorsAction::class,
            MobileApprovedTransitionAction::class,
            RoundForwardPriceAction::class,
        ],
    ],
    [
        'target' => 'approved',
        'actions' => [
            CheckPotentialFraudIndicatorsAction::class,
            ApprovedTransitionAction::class,
            RoundForwardPriceAction::class,
        ],
    ],
];

// Used throughout the machine definition
'on' => [
    ApprovedEvent::class => self::APPROVED_TRANSITION,
]
```

## Context-First Testing Strategy

### Comprehensive Context Setup

Production tests prioritize proper context setup before executing machine logic:

```php
public function test_complex_application_flow(): void
{
    // 1️⃣ Arrange 🏗 - Comprehensive setup
    $salesChannel = SalesChannel::factory()->forDirectForward()->create();
    $retailer = Retailer::factory()->withStockInputs()->create();
    $farmer = Farmer::factory()->verified()->create();
    
    $event = ApplicationStartedEvent::make([
        'nin' => $farmer->nin,
        'retailer_id' => $retailer->id,
        'order_items' => OrderItemFactory::new()->count(3)->make(),
        'farmer_payment_date' => now()->addDays(30)->format('Y-m-d'),
        'payment_method' => PaymentMethod::BankTransfer->value,
    ]);
    
    $context = ApplicationContext::from([
        'retailer' => $retailer,
        'salesChannelType' => SalesChannelType::from($salesChannel->id),
        'platformType' => PlatformType::Retailer,
    ]);
    
    // Run prerequisite guards to build complete context
    OrderItemsContextGuard::run($context, $event);
    CalculateStockDetailsGuard::run($context);
    CalculatePricesGuard::run($context);
    
    // 2️⃣ Act 🏋🏻‍
    $action = new CreateApplicationAction();
    $action($context, $event);
    
    // 3️⃣ Assert ✅
    $this->assertDatabaseHas(Application::class, [
        'farmer_id' => $farmer->id,
        'retailer_id' => $retailer->id,
        'status' => ApplicationStatus::PENDING,
    ]);
    
    $this->assertTrue($context->order->relationLoaded('retailer'));
    $this->assertTrue($context->order->relationLoaded('farmer'));
}
```

### Context Calculators in Tests

Production applications use context calculators to build complex context data:

```php
public function test_mobile_application_context_calculation(): void
{
    $event = MobileApplicationStartedEvent::make([
        'order_items' => [
            ['product_id' => 1, 'quantity' => 100],
            ['product_id' => 2, 'quantity' => 50],
        ]
    ]);
    
    $context = ApplicationContext::from([]);
    
    // Use production calculators
    MobileApplicationContextCalculator::run($context, $event);
    OrderItemsCalculator::run($context, $event);
    
    $this->assertInstanceOf(Collection::class, $context->orderItems);
    $this->assertCount(2, $context->orderItems);
    $this->assertInstanceOf(PlatformType::class, $context->platformType);
}
```

## Action Testing Patterns

### Isolating Action Logic

Each action is tested independently with proper context and event setup:

```php
class CreateApplicationActionTest extends TestCase
{
    use RefreshDatabase;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Suppress model observers that might interfere
        Application::ignoreObservableEvents(['saved', 'updated']);
        Order::ignoreObservableEvents(['saved']);
    }
    
    #[Test]
    public function it_creates_application_with_proper_relationships(): void
    {
        // Arrange
        $retailer = Retailer::factory()
            ->withStockInputs()
            ->withCommissionRates()
            ->create();
            
        $farmer = Farmer::factory()->create([
            'nin' => '12345678901',
        ]);
        
        $event = ApplicationStartedEvent::make([
            'nin' => $farmer->nin,
            'retailer_id' => $retailer->id,
            'order_items' => [
                [
                    'product_id' => Product::factory()->create()->id,
                    'quantity' => 100,
                    'unit_price' => 10.50,
                ]
            ],
            'payment_method' => PaymentMethod::BankTransfer->value,
        ]);
        
        $context = ApplicationContext::from([
            'retailer' => $retailer,
            'salesChannelType' => SalesChannelType::ForwardSale,
        ]);
        
        // Act
        $action = new CreateApplicationAction();
        $action($context, $event);
        
        // Assert
        $application = Application::where('farmer_id', $farmer->id)->first();
        
        $this->assertNotNull($application);
        $this->assertEquals($retailer->id, $application->retailer_id);
        $this->assertInstanceOf(Order::class, $application->order);
        $this->assertCount(1, $application->order->orderItems);
        
        // Verify context is updated
        $this->assertEquals($application->id, $context->application?->id);
        $this->assertEquals($application->order->id, $context->order?->id);
    }
    
    #[DataProvider('applicationTypes')]
    #[Test]
    public function it_handles_different_application_types(
        SalesChannelType $salesChannelType,
        PaymentMethod $paymentMethod,
        ApplicationStatus $expectedStatus
    ): void {
        // Test multiple scenarios with same logic
        $context = ApplicationContext::from([
            'salesChannelType' => $salesChannelType,
        ]);
        
        $event = ApplicationStartedEvent::make([
            'payment_method' => $paymentMethod->value,
        ]);
        
        $action = new CreateApplicationAction();
        $action($context, $event);
        
        $this->assertEquals($expectedStatus, $context->application->status);
    }
    
    public static function applicationTypes(): array
    {
        return [
            'Forward Sale' => [
                SalesChannelType::ForwardSale,
                PaymentMethod::BankTransfer,
                ApplicationStatus::PENDING
            ],
            'Direct Cash' => [
                SalesChannelType::DirectCash,
                PaymentMethod::Cash,
                ApplicationStatus::APPROVED
            ],
            'Virtual POS' => [
                SalesChannelType::VirtualPos,
                PaymentMethod::CreditCard,
                ApplicationStatus::PENDING
            ],
        ];
    }
}
```

### Event Queue Verification

Production actions often trigger additional events. Test these using event queue inspection:

```php
#[Test]
public function it_triggers_appropriate_events(): void
{
    // Arrange & Act
    $action = new ApprovedTransitionAction();
    $action($context, $event);
    
    // Assert event queue contains expected events
    $eventQueue = $this->invokePrivatePropertyForTesting($action, 'eventQueue');
    
    $this->assertTrue($eventQueue->contains(
        fn ($event): bool => $event instanceof ApprovedEvent
    ));
    
    $this->assertTrue($eventQueue->contains(
        fn ($event): bool => $event instanceof NotificationEvent
    ));
}

protected function invokePrivatePropertyForTesting($object, $property)
{
    $reflectedClass = new ReflectionClass($object);
    $reflection = $reflectedClass->getProperty($property);
    $reflection->setAccessible(true);
    return $reflection->getValue($object);
}
```

## Guard Testing Strategies

### Required Context Validation

Guards define required context attributes. Test these explicitly:

```php
class ApplicationPermitGuardTest extends TestCase
{
    #[Test]
    public function it_requires_context_attributes(): void
    {
        $this->assertEquals([
            'totalCashPriceWithVat' => Money::class,
            'totalForwardPriceWithVat' => Money::class,
            'salesChannelType' => SalesChannelType::class,
            'retailer' => Retailer::class,
        ], ApplicationPermitGuard::$requiredContext);
    }
    
    #[Test]
    public function it_fails_with_missing_context(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Required context attribute missing: retailer');
        
        $context = ApplicationContext::from([]);
        $event = ApplicationStartedEvent::make([]);
        
        ApplicationPermitGuard::run($context, $event);
    }
}
```

### Platform-Specific Testing

Many guards have different behavior based on platform (mobile vs retailer):

```php
#[Test]
public function it_has_different_behavior_for_mobile_platform(): void
{
    // Mobile context
    $mobileContext = ApplicationContext::from([
        'platformType' => PlatformType::Mobile,
        'orderItems' => collect([
            OrderItemData::from([
                'unit_price' => Money::of(15, 'TRY'), // Out of range
                'quantity' => 100,
            ])
        ]),
    ]);
    
    // Retailer context  
    $retailerContext = ApplicationContext::from([
        'platformType' => PlatformType::Retailer,
        'orderItems' => collect([
            OrderItemData::from([
                'unit_price' => Money::of(15, 'TRY'), // Same price
                'quantity' => 100,
            ])
        ]),
    ]);
    
    $event = ApplicationStartedEvent::make([]);
    
    // Mobile should pass (more lenient validation)
    $this->assertTrue(OrderItemsValidationGuard::run($mobileContext, $event));
    
    // Retailer should fail (strict validation)
    $this->assertFalse(OrderItemsValidationGuard::run($retailerContext, $event));
}
```

### Complex Business Rule Testing

Guards often implement complex business rules. Test edge cases thoroughly:

```php
#[Test]
public function it_validates_crop_equivalent_payment_date_range(): void
{
    $product = Product::factory()->vegetable()->create();
    
    // Valid date range (within harvest season)
    $validContext = ApplicationContext::from([
        'orderItems' => collect([
            OrderItemData::from([
                'product' => $product,
                'payment_date' => Carbon::parse('2024-06-15'), // Harvest season
            ])
        ]),
    ]);
    
    $this->assertTrue(
        DateRangeAllowedForCropEquivalentPaymentGuard::run($validContext, $event)
    );
    
    // Invalid date range (outside harvest season)
    $invalidContext = ApplicationContext::from([
        'orderItems' => collect([
            OrderItemData::from([
                'product' => $product,
                'payment_date' => Carbon::parse('2024-12-15'), // Off season
            ])
        ]),
    ]);
    
    $this->assertFalse(
        DateRangeAllowedForCropEquivalentPaymentGuard::run($invalidContext, $event)
    );
}
```

## Laravel Service Integration

### Facade Mocking for External Services

Production applications integrate with external services. Mock these appropriately:

```php
#[Test]
public function it_integrates_with_external_scoring_service(): void
{
    // Mock external service
    TarfinScore::shouldReceive('protocolDecision')
        ->withSomeOfArgs($application, $specifications)
        ->andReturn([
            'satisfied' => true,
            'application_status' => ApplicationStatus::APPROVED->value,
            'decision_type' => ApplicationDecisionType::AUTO_APPROVED->value,
            'specifications' => ['LowRiskSpecification'],
        ]);
    
    // Mock notification service
    NotificationService::shouldReceive('send')
        ->once()
        ->with(Mockery::type(ApprovedNotification::class));
    
    $action = new ProtocolAction();
    $action($context, $event);
    
    $this->assertEquals(ApplicationStatus::APPROVED, $context->application->status);
}
```

### Database Transaction Testing

Test actions that involve complex database operations:

```php
#[Test]
public function it_handles_database_transactions_correctly(): void
{
    DB::beginTransaction();
    
    try {
        $action = new CreateApplicationAction();
        $action($context, $event);
        
        // Verify changes are visible within transaction
        $this->assertDatabaseHas(Application::class, ['id' => $context->application->id]);
        $this->assertDatabaseHas(Order::class, ['application_id' => $context->application->id]);
        
        DB::commit();
    } catch (Exception $e) {
        DB::rollBack();
        throw $e;
    }
    
    // Verify changes are persisted after commit
    $this->assertDatabaseHas(Application::class, ['id' => $context->application->id]);
}
```

### Authentication Integration

Test machine actions that require specific user authentication:

```php
#[Test]
public function it_requires_retailer_authentication(): void
{
    $retailer = Retailer::factory()->withOwnerUser()->create();
    
    // Acting as retailer user
    Passport::actingAs($retailer->owner()->first(), [], 'retailer');
    
    $context = ApplicationContext::from([
        'retailer' => $retailer,
        'authenticatedUser' => $retailer->owner()->first(),
    ]);
    
    $action = new ApproveApplicationAction();
    $action($context, $event);
    
    $this->assertEquals(ApplicationStatus::APPROVED, $context->application->status);
}

protected function actingAsRetailerUser(?Retailer $retailer = null): Retailer
{
    $retailer = $retailer ?? Retailer::factory()->withOwnerUser()->create();
    Passport::actingAs($retailer->owner()->first(), [], 'retailer');
    return $retailer;
}
```

## Testing Organization Patterns

### Base Test Classes

Create base test classes for common setup and utilities:

```php
abstract class MachineTestCase extends TestCase
{
    use RefreshDatabase;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Common setup for all machine tests
        Application::ignoreObservableEvents(['saved', 'updated']);
        Order::ignoreObservableEvents(['saved']);
        
        // Mock time-sensitive operations
        Carbon::setTestNow('2024-01-15 10:00:00');
    }
    
    protected function createBasicApplicationContext(array $overrides = []): ApplicationContext
    {
        $retailer = Retailer::factory()->withStockInputs()->create();
        $farmer = Farmer::factory()->verified()->create();
        
        return ApplicationContext::from(array_merge([
            'retailer' => $retailer,
            'farmer' => $farmer,
            'salesChannelType' => SalesChannelType::ForwardSale,
            'platformType' => PlatformType::Retailer,
        ], $overrides));
    }
    
    protected function createApplicationStartedEvent(array $overrides = []): ApplicationStartedEvent
    {
        return ApplicationStartedEvent::make(array_merge([
            'nin' => $this->faker->tckn(),
            'order_items' => [
                [
                    'product_id' => Product::factory()->create()->id,
                    'quantity' => 100,
                    'unit_price' => 10.50,
                ]
            ],
            'payment_method' => PaymentMethod::BankTransfer->value,
        ], $overrides));
    }
}
```

### Trait-Based Environment Setup

Use traits for environment-specific testing configurations:

```php
trait RomaniaEnvironmentTrait
{
    protected function setUpRomaniaEnvironment(): void
    {
        config(['app.market' => 'romania']);
        config(['services.credit_bureau.enabled' => true]);
        
        // Set up market-specific factories and models
        Market::factory()->romania()->create();
    }
}

class RomaniaApplicationTest extends MachineTestCase
{
    use RomaniaEnvironmentTrait;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRomaniaEnvironment();
    }
}
```

### Custom Assertion Methods

Create domain-specific assertions for better test readability:

```php
trait ApplicationAssertions
{
    protected function assertApplicationInState(string $expectedState, Application $application): void
    {
        $this->assertEquals($expectedState, $application->machine_state);
        $this->assertEquals($expectedState, $application->status->value);
    }
    
    protected function assertEventQueueContains(string $eventClass, Collection $eventQueue): void
    {
        $this->assertTrue(
            $eventQueue->contains(fn ($event) => $event instanceof $eventClass),
            "Event queue does not contain expected event: {$eventClass}"
        );
    }
    
    protected function assertContextHasRequiredAttributes(array $requiredAttributes, ApplicationContext $context): void
    {
        foreach ($requiredAttributes as $attribute => $type) {
            $this->assertObjectHasAttribute($attribute, $context);
            $this->assertInstanceOf($type, $context->$attribute);
        }
    }
}
```

## Scenario-Based Testing

### Machine Scenarios for Edge Cases

Production machines use scenarios to test specific business flows:

```php
// In machine definition
'scenarios_enabled' => !App::environment('production'),
'scenarios' => [
    'high_risk_fraud_detection_scenario' => [
        'protocol' => [
            'checking' => [
                'entry' => [
                    'addPotentialFraud', // Inline action for testing
                    FraudDetectionScenarioAction::class,
                ],
                'on' => [
                    FraudDetectedEvent::class => self::FRAUD_DETECTED_TRANSITION,
                ],
            ],
        ],
    ],
],

// In test
#[Test]
public function it_handles_fraud_detection_scenario(): void
{
    config(['machine.scenario' => 'high_risk_fraud_detection_scenario']);
    
    $machine = ApplicationMachine::create();
    $machine->send(ProtocolStartedEvent::class);
    
    $this->assertEquals('fraud.detected', $machine->state->value);
    
    // Verify fraud indicators were added to context
    $this->assertNotEmpty($machine->state->context->potentialFraudIndicators);
}
```

These production patterns demonstrate sophisticated approaches to testing complex state machines while maintaining code quality, test reliability, and comprehensive coverage of business logic.