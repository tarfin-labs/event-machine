# Laravel Integration Guide

EventMachine is designed to integrate seamlessly with Laravel applications, providing powerful state machine capabilities that work naturally with Laravel's ecosystem. This guide covers comprehensive integration patterns based on real-world production usage.

## Overview

EventMachine leverages Laravel's service container, dependency injection, and ecosystem components while maintaining its own behavior resolution system. The integration enables:

- Natural Laravel service usage in machine behaviors
- Eloquent model integration with automatic state persistence
- Event and job queue integration
- Laravel authentication and authorization
- Database transactions and relationships
- Testing with Laravel's testing framework

## Service Container Integration

### Dependency Injection in Behaviors

EventMachine behaviors can use Laravel services through constructor injection:

```php
use App\Services\PaymentService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class ProcessPaymentAction extends ActionBehavior
{
    public function __construct(
        private PaymentService $paymentService,
        private NotificationService $notificationService
    ) {}
    
    public function __invoke(ContextManager $context, EventBehavior $event): void
    {
        $order = $context->get('order');
        
        try {
            $result = $this->paymentService->processPayment(
                amount: $order->total,
                paymentMethod: $event->payload['payment_method']
            );
            
            $context->set('payment_result', $result);
            
            if ($result['success']) {
                $this->notificationService->sendPaymentConfirmation($order);
            }
            
        } catch (PaymentException $e) {
            Log::error('Payment processing failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
}
```

### Facade Usage

Laravel facades work naturally within machine behaviors:

```php
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;

class SendInvoiceAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): void
    {
        $order = $context->get('order');
        
        // Generate invoice PDF
        $invoicePdf = $this->generateInvoicePdf($order);
        
        // Store in cloud storage
        $path = Storage::disk('s3')->put(
            "invoices/{$order->id}.pdf",
            $invoicePdf
        );
        
        // Cache invoice URL for quick access
        Cache::put(
            "invoice_url_{$order->id}",
            Storage::disk('s3')->url($path),
            3600
        );
        
        // Send via email
        Mail::to($order->customer->email)
            ->send(new InvoiceMail($order, $path));
    }
}
```

## Database Integration

### Eloquent Model Integration

EventMachine works seamlessly with Eloquent models through context and direct usage:

```php
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Customer;

class CreateOrderAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): void
    {
        $customer = Customer::findOrFail($event->payload['customer_id']);
        
        $order = Order::create([
            'customer_id' => $customer->id,
            'total' => $event->payload['total'],
            'status' => 'pending',
        ]);
        
        // Create order items
        foreach ($event->payload['items'] as $item) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
            ]);
        }
        
        // Load relationships for context
        $order->load(['items', 'customer']);
        
        $context->set('order', $order);
        $context->set('customer', $customer);
    }
}
```

### Database Transactions

Handle complex operations with database transactions:

```php
use Illuminate\Support\Facades\DB;

class CompleteOrderWorkflowAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): void
    {
        DB::transaction(function () use ($context, $event) {
            $order = $context->get('order');
            
            // Update order status
            $order->update(['status' => 'completed']);
            
            // Create invoice
            $invoice = $order->invoices()->create([
                'amount' => $order->total,
                'status' => 'issued',
            ]);
            
            // Update inventory
            foreach ($order->items as $item) {
                $item->product->decrement('stock', $item->quantity);
            }
            
            // Create accounting entries
            $this->createAccountingEntries($order, $invoice);
            
            $context->set('invoice', $invoice);
        });
    }
    
    private function createAccountingEntries(Order $order, Invoice $invoice): void
    {
        // Complex accounting logic that must be atomic
        AccountingEntry::create([
            'type' => 'debit',
            'amount' => $order->total,
            'account' => 'accounts_receivable',
            'reference_id' => $invoice->id,
        ]);
        
        AccountingEntry::create([
            'type' => 'credit',
            'amount' => $order->total,
            'account' => 'sales_revenue',
            'reference_id' => $invoice->id,
        ]);
    }
}
```

### Query Builder Integration

Use Laravel Query Builder for complex data operations:

```php
use Illuminate\Support\Facades\DB;

class CalculateCustomerLimitGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): bool
    {
        $customer = $context->get('customer');
        $requestedAmount = $event->payload['amount'];
        
        // Calculate customer's current outstanding balance
        $outstandingBalance = DB::table('orders')
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['pending', 'processing'])
            ->sum('total');
        
        // Get customer's credit limit based on history
        $creditLimit = DB::table('orders')
            ->where('customer_id', $customer->id)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subMonths(12))
            ->avg('total') * 5; // 5x average order value
        
        $availableCredit = $creditLimit - $outstandingBalance;
        
        return $requestedAmount <= $availableCredit;
    }
}
```

## Event and Job Integration

### Laravel Event Integration

Trigger Laravel events from machine actions:

```php
use App\Events\OrderStatusChanged;
use App\Events\CustomerNotification;

class OrderStatusUpdateAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): void
    {
        $order = $context->get('order');
        $newStatus = $event->payload['status'];
        $oldStatus = $order->status;
        
        $order->update(['status' => $newStatus]);
        
        // Trigger Laravel event for other parts of the application
        event(new OrderStatusChanged($order, $oldStatus, $newStatus));
        
        // Trigger customer notification event
        event(new CustomerNotification(
            $order->customer,
            "Your order #{$order->id} is now {$newStatus}"
        ));
    }
}
```

### Job Queue Integration

Dispatch background jobs from machine actions:

```php
use App\Jobs\ProcessPayment;
use App\Jobs\SendOrderConfirmation;
use App\Jobs\UpdateInventory;

class ProcessOrderAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): void
    {
        $order = $context->get('order');
        
        // Dispatch payment processing job
        ProcessPayment::dispatch($order)
            ->onQueue('payments')
            ->delay(now()->addMinutes(5));
        
        // Dispatch confirmation email job
        SendOrderConfirmation::dispatch($order)
            ->onQueue('emails');
        
        // Dispatch inventory update job
        UpdateInventory::dispatch($order)
            ->onQueue('inventory')
            ->afterCommit(); // Only after database transaction commits
    }
}
```

### Chain Jobs with Machine State

Create job chains that progress machine state:

```php
use Illuminate\Bus\Chain;

class InitiateOrderFulfillmentAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): void
    {
        $order = $context->get('order');
        $machine = $context->get('machine');
        
        // Create job chain for order fulfillment
        Chain::dispatch([
            new ValidateInventory($order),
            new ReserveStock($order),
            new GeneratePickingList($order),
            new NotifyWarehouse($order),
            new AdvanceMachineState($machine, 'STOCK_RESERVED'),
        ])->onConnection('redis')
          ->onQueue('fulfillment')
          ->catch(function (Throwable $e) use ($machine) {
              // Handle chain failure
              $machine->send('FULFILLMENT_FAILED', ['error' => $e->getMessage()]);
          });
    }
}
```

## Authentication and Authorization

### User Context in Machines

Pass authenticated user context to machines:

```php
use Illuminate\Support\Facades\Auth;

class UserActionContext extends ContextManager
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
        
        // Automatically include current user if authenticated
        if (Auth::check()) {
            $this->set('authenticated_user', Auth::user());
        }
    }
    
    public function getAuthenticatedUser(): ?User
    {
        return $this->get('authenticated_user');
    }
    
    public function requiresAuthentication(): bool
    {
        return $this->getAuthenticatedUser() !== null;
    }
}
```

### Authorization Guards

Implement authorization checks as guards:

```php
use Illuminate\Support\Facades\Gate;

class CanUpdateOrderGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): bool
    {
        $user = $context->get('authenticated_user');
        $order = $context->get('order');
        
        if (!$user) {
            return false;
        }
        
        // Use Laravel Gates for authorization
        return Gate::forUser($user)->allows('update', $order);
    }
}

// In AuthServiceProvider
Gate::define('update', function (User $user, Order $order) {
    return $user->id === $order->customer_id || $user->hasRole('admin');
});
```

### Role-Based Machine Behavior

Adapt machine behavior based on user roles:

```php
class RoleBasedApprovalAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): void
    {
        $user = $context->get('authenticated_user');
        $order = $context->get('order');
        
        if ($user->hasRole('manager')) {
            // Managers can approve directly
            $order->update(['status' => 'approved']);
            $context->set('approval_type', 'manager_approved');
            
        } elseif ($user->hasRole('supervisor')) {
            // Supervisors need additional verification for large orders
            if ($order->total > 10000) {
                $order->update(['status' => 'pending_manager_approval']);
                $context->set('approval_type', 'escalated_to_manager');
            } else {
                $order->update(['status' => 'approved']);
                $context->set('approval_type', 'supervisor_approved');
            }
            
        } else {
            // Regular users cannot approve
            throw new UnauthorizedException('Insufficient privileges to approve order');
        }
    }
}
```

## Configuration and Environment

### Environment-Based Machine Configuration

Adapt machine behavior based on environment:

```php
class EnvironmentAwareMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id' => 'order_processing',
                'initial' => 'pending',
                'states' => [
                    'pending' => [
                        'on' => [
                            'PROCESS' => [
                                'target' => app()->environment('production') 
                                    ? 'processing' 
                                    : 'auto_approved', // Skip processing in dev/test
                                'guards' => app()->environment('production') 
                                    ? [PaymentValidGuard::class] 
                                    : [], // Skip guards in non-production
                            ],
                        ],
                    ],
                    'processing' => [/* ... */],
                    'auto_approved' => [/* ... */],
                ],
            ]
        );
    }
}
```

### Configuration-Driven Behavior

Use Laravel configuration for machine customization:

```php
// In config/machines.php
return [
    'order_processing' => [
        'auto_approval_threshold' => env('ORDER_AUTO_APPROVAL_THRESHOLD', 1000),
        'notification_channels' => ['email', 'sms'],
        'payment_timeout' => env('PAYMENT_TIMEOUT_MINUTES', 30),
    ],
];

// In machine action
class AutoApprovalGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): bool
    {
        $order = $context->get('order');
        $threshold = config('machines.order_processing.auto_approval_threshold');
        
        return $order->total <= $threshold;
    }
}
```

## Testing Integration

### Laravel Test Database

EventMachine tests work naturally with Laravel's testing database setup:

```php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderProcessingMachineTest extends TestCase
{
    use RefreshDatabase;
    
    public function test_complete_order_workflow(): void
    {
        // Use Laravel factories
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['stock' => 100]);
        
        $machine = OrderProcessingMachine::create([
            'context' => [
                'customer' => $customer,
            ]
        ]);
        
        // Start the workflow
        $machine->send('START_ORDER', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 5]
            ]
        ]);
        
        // Assert database changes
        $this->assertDatabaseHas('orders', [
            'customer_id' => $customer->id,
            'status' => 'pending',
        ]);
        
        // Continue workflow
        $machine->send('APPROVE_ORDER');
        
        $this->assertEquals('approved', $machine->state->value);
    }
}
```

### Mocking Laravel Services

Mock Laravel services in machine tests:

```php
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

public function test_order_sends_confirmation_email(): void
{
    Mail::fake();
    Queue::fake();
    
    $machine = OrderProcessingMachine::create();
    $machine->send('COMPLETE_ORDER');
    
    // Assert email was sent
    Mail::assertSent(OrderConfirmationMail::class, function ($mail) {
        return $mail->order->id === $this->order->id;
    });
    
    // Assert job was dispatched
    Queue::assertPushed(UpdateInventoryJob::class);
}
```

### Feature Testing with Machines

Test API endpoints that interact with machines:

```php
public function test_api_endpoint_advances_machine_state(): void
{
    $user = User::factory()->create();
    $order = Order::factory()->create(['customer_id' => $user->id]);
    
    $response = $this->actingAs($user)
        ->postJson("/api/orders/{$order->id}/approve", [
            'notes' => 'Approved by customer',
        ]);
    
    $response->assertStatus(200)
        ->assertJson([
            'status' => 'approved',
            'message' => 'Order approved successfully',
        ]);
    
    // Verify machine state was updated
    $this->assertEquals('approved', $order->fresh()->machine_state);
}
```

## Performance Considerations

### Eager Loading Relationships

Optimize database queries by eager loading relationships:

```php
class LoadOrderDataAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): void
    {
        $orderId = $event->payload['order_id'];
        
        // Eager load all required relationships
        $order = Order::with([
            'customer',
            'items.product',
            'payments',
            'shipping_address',
        ])->findOrFail($orderId);
        
        $context->set('order', $order);
    }
}
```

### Caching Machine State

Cache frequently accessed machine state:

```php
use Illuminate\Support\Facades\Cache;

class CachedMachineStateLookupAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): void
    {
        $orderId = $event->payload['order_id'];
        $cacheKey = "machine_state:order:{$orderId}";
        
        $machineState = Cache::remember($cacheKey, 300, function () use ($orderId) {
            return Order::find($orderId)->machine_state;
        });
        
        $context->set('current_state', $machineState);
    }
}
```

### Queue Processing Optimization

Optimize job queue processing for machine operations:

```php
// In config/queue.php
'connections' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => env('REDIS_QUEUE', 'default'),
        'retry_after' => 90,
        'block_for' => null,
    ],
],

// Separate queues for different machine operations
'machine_queues' => [
    'high_priority' => 'machine_high',
    'normal' => 'machine_normal',
    'background' => 'machine_background',
],
```

EventMachine's Laravel integration provides a powerful foundation for building complex, stateful applications that leverage the full Laravel ecosystem while maintaining clean separation of concerns and testable architecture.