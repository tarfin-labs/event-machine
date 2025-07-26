# Order Processing State Machine

This comprehensive example demonstrates an e-commerce order processing system using EventMachine. It showcases complex business logic, error handling, compensation patterns, and integration with external services.

## System Overview

Our order processing system handles:
- Order validation and inventory checking
- Payment processing with retries
- Shipping coordination  
- Customer notifications
- Refund and cancellation handling
- Error recovery and compensation

## Context Definition

```php
<?php

namespace App\Contexts;

use Tarfinlabs\EventMachine\ContextManager;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Email;
use Carbon\Carbon;

class OrderContext extends ContextManager
{
    public function __construct(
        public string|Optional $orderId,
        public int|Optional $customerId,
        #[Email]
        public string|Optional $customerEmail,
        public string|Optional $customerName,
        public array|Optional $items,
        #[Min(0)]
        public float|Optional $subtotal,
        #[Min(0)]
        public float|Optional $taxAmount,
        #[Min(0)]
        public float|Optional $shippingCost,
        #[Min(0)]
        public float|Optional $total,
        public array|Optional $shippingAddress,
        public array|Optional $billingAddress,
        public string|Optional $paymentMethod,
        public string|Optional $paymentId,
        public int|Optional $paymentRetries,
        public array|Optional $inventoryReservations,
        public string|Optional $shippingMethod,
        public string|Optional $trackingNumber,
        public array|Optional $notifications,
        public string|Optional $errorMessage,
        public Carbon|Optional $createdAt,
        public Carbon|Optional $processedAt,
        public Carbon|Optional $shippedAt,
        public Carbon|Optional $deliveredAt
    ) {
        parent::__construct();
        
        // Set defaults
        if ($this->orderId instanceof Optional) {
            $this->orderId = 'ORD-' . strtoupper(substr(md5(uniqid()), 0, 8));
        }
        if ($this->items instanceof Optional) {
            $this->items = [];
        }
        if ($this->subtotal instanceof Optional) {
            $this->subtotal = 0.0;
        }
        if ($this->paymentRetries instanceof Optional) {
            $this->paymentRetries = 0;
        }
        if ($this->inventoryReservations instanceof Optional) {
            $this->inventoryReservations = [];
        }
        if ($this->notifications instanceof Optional) {
            $this->notifications = [];
        }
        if ($this->createdAt instanceof Optional) {
            $this->createdAt = now();
        }
    }

    // Helper methods
    public function getTotalItems(): int
    {
        return array_sum(array_column($this->items, 'quantity'));
    }

    public function hasItems(): bool
    {
        return count($this->items) > 0;
    }

    public function isPremiumCustomer(): bool
    {
        // Logic to determine if customer is premium
        return $this->total >= 500 || $this->customerId < 1000; // Example logic
    }

    public function canRetryPayment(): bool
    {
        return $this->paymentRetries < 3;
    }

    public function addNotification(string $type, string $message): void
    {
        $this->notifications[] = [
            'type' => $type,
            'message' => $message,
            'timestamp' => now()->toISOString()
        ];
    }

    public function calculateTotals(): void
    {
        $this->subtotal = array_sum(array_map(
            fn($item) => $item['price'] * $item['quantity'],
            $this->items
        ));
        
        $this->taxAmount = $this->subtotal * 0.08; // 8% tax
        $this->total = $this->subtotal + $this->taxAmount + $this->shippingCost;
    }

    public function requiresExpressShipping(): bool
    {
        return $this->shippingMethod === 'express' || $this->isPremiumCustomer();
    }
}
```

## Main Order Processing Machine

```php
<?php

namespace App\Machines;

use App\Contexts\OrderContext;
use App\Actions\Order\*;
use App\Guards\Order\*;
use App\Events\Order\*;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class OrderProcessingMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial' => 'validating',
                'context' => OrderContext::class,
                'states' => [
                    'validating' => [
                        'entry' => 'validateOrder',
                        'on' => [
                            'VALIDATION_SUCCESS' => [
                                'target' => 'reservingInventory',
                                'actions' => 'calculateTotals'
                            ],
                            'VALIDATION_FAILED' => [
                                'target' => 'rejected',
                                'actions' => 'recordValidationError'
                            ]
                        ]
                    ],
                    
                    'reservingInventory' => [
                        'entry' => 'reserveInventory',
                        'on' => [
                            'INVENTORY_RESERVED' => 'processingPayment',
                            'INVENTORY_INSUFFICIENT' => [
                                'target' => 'rejected',
                                'actions' => 'handleInsufficientInventory'
                            ]
                        ]
                    ],
                    
                    'processingPayment' => [
                        'entry' => 'processPayment',
                        'on' => [
                            'PAYMENT_SUCCESS' => [
                                'target' => 'fulfillment',
                                'actions' => ['recordPayment', 'sendOrderConfirmation']
                            ],
                            'PAYMENT_FAILED' => [
                                [
                                    'target' => 'paymentRetry',
                                    'guards' => 'canRetryPayment',
                                    'actions' => 'incrementPaymentRetries'
                                ],
                                [
                                    'target' => 'paymentFailed',
                                    'actions' => 'handlePaymentFailure'
                                ]
                            ]
                        ]
                    ],
                    
                    'paymentRetry' => [
                        'entry' => 'schedulePaymentRetry',
                        'on' => [
                            'RETRY_PAYMENT' => 'processingPayment',
                            'CANCEL_ORDER' => [
                                'target' => 'cancelled',
                                'actions' => 'releaseInventory'
                            ]
                        ]
                    ],
                    
                    'paymentFailed' => [
                        'entry' => ['releaseInventory', 'notifyPaymentFailure'],
                        'on' => [
                            'RETRY_PAYMENT' => 'processingPayment',
                            'CANCEL_ORDER' => 'cancelled'
                        ]
                    ],
                    
                    'fulfillment' => [
                        'initial' => 'preparing',
                        'states' => [
                            'preparing' => [
                                'entry' => 'startFulfillment',
                                'on' => [
                                    'ITEMS_PREPARED' => [
                                        [
                                            'target' => 'expressShipping',
                                            'guards' => 'requiresExpressShipping'
                                        ],
                                        [
                                            'target' => 'standardShipping'
                                        ]
                                    ],
                                    'PREPARATION_FAILED' => '#fulfillmentError'
                                ]
                            },
                            
                            'standardShipping' => [
                                'entry' => 'scheduleStandardShipping',
                                'on' => [
                                    'SHIPPED' => '#shipped',
                                    'SHIPPING_FAILED' => '#fulfillmentError'
                                ]
                            ],
                            
                            'expressShipping' => [
                                'entry' => 'scheduleExpressShipping',
                                'on' => [
                                    'SHIPPED' => '#shipped',
                                    'SHIPPING_FAILED' => '#fulfillmentError'
                                ]
                            }
                        ],
                        'on' => [
                            'CANCEL_ORDER' => [
                                'target' => 'cancelling',
                                'guards' => 'canCancelOrder'
                            ],
                            'FULFILLMENT_ERROR' => {
                                'id' => 'fulfillmentError',
                                'target' => 'fulfillmentError'
                            }
                        ]
                    ],
                    
                    'shipped' => [
                        'id' => 'shipped',
                        'entry' => ['recordShipping', 'sendShippingNotification'],
                        'on' => [
                            'DELIVERED' => [
                                'target' => 'completed',
                                'actions' => 'recordDelivery'
                            ],
                            'DELIVERY_FAILED' => 'deliveryIssue',
                            'RETURN_REQUESTED' => [
                                'target' => 'returningItems',
                                'guards' => 'canProcessReturn'
                            ]
                        ]
                    ],
                    
                    'deliveryIssue' => [
                        'entry' => 'handleDeliveryIssue',
                        'on' => [
                            'REDELIVER' => 'shipped',
                            'REFUND' => 'refunding',
                            'CUSTOMER_PICKUP' => 'completed'
                        ]
                    ],
                    
                    'returningItems' => [
                        'entry' => 'initiateReturn',
                        'on' => [
                            'RETURN_RECEIVED' => [
                                'target' => 'refunding',
                                'actions' => 'processReturnedItems'
                            ],
                            'RETURN_REJECTED' => [
                                'target' => 'completed',
                                'actions' => 'notifyReturnRejection'
                            ]
                        ]
                    ],
                    
                    'refunding' => [
                        'entry' => 'processRefund',
                        'on' => [
                            'REFUND_SUCCESS' => [
                                'target' => 'refunded',
                                'actions' => 'sendRefundConfirmation'
                            ],
                            'REFUND_FAILED' => [
                                'target' => 'refundError',
                                'actions' => 'handleRefundError'
                            ]
                        ]
                    ],
                    
                    'cancelling' => [
                        'entry' => 'initiateCancellation',
                        'on' => [
                            'CANCELLATION_SUCCESS' => [
                                'target' => 'cancelled',
                                'actions' => ['releaseInventory', 'processRefund']
                            ],
                            'CANCELLATION_FAILED' => 'cancellationError'
                        ]
                    ],
                    
                    // Final states
                    'completed' => {
                        'type' => 'final',
                        'entry' => 'recordCompletion'
                    },
                    'cancelled' => {
                        'type' => 'final',
                        'entry' => 'recordCancellation'
                    },
                    'rejected' => {
                        'type' => 'final',
                        'entry' => 'recordRejection'
                    },
                    'refunded' => {
                        'type' => 'final',
                        'entry' => 'recordRefund'
                    },
                    
                    // Error states
                    'fulfillmentError' => [
                        'entry' => 'handleFulfillmentError',
                        'on' => [
                            'RETRY_FULFILLMENT' => 'fulfillment.preparing',
                            'CANCEL_ORDER' => 'cancelling',
                            'REFUND_CUSTOMER' => 'refunding'
                        ]
                    ],
                    'refundError' => [
                        'entry' => 'escalateRefundError',
                        'on' => [
                            'RETRY_REFUND' => 'refunding',
                            'MANUAL_REFUND' => 'refunded'
                        ]
                    ],
                    'cancellationError' => [
                        'entry' => 'escalateCancellationError',
                        'on' => [
                            'RETRY_CANCELLATION' => 'cancelling',
                            'FORCE_CANCELLATION' => 'cancelled'
                        ]
                    ]
                ]
            ],
            behavior: [
                'events' => [
                    'PLACE_ORDER' => PlaceOrderEvent::class,
                    'CANCEL_ORDER' => CancelOrderEvent::class,
                    'RETURN_REQUESTED' => ReturnRequestEvent::class
                ],
                'actions' => [
                    'validateOrder' => ValidateOrderAction::class,
                    'calculateTotals' => CalculateTotalsAction::class,
                    'reserveInventory' => ReserveInventoryAction::class,
                    'processPayment' => ProcessPaymentAction::class,
                    'recordPayment' => RecordPaymentAction::class,
                    'sendOrderConfirmation' => SendOrderConfirmationAction::class,
                    'startFulfillment' => StartFulfillmentAction::class,
                    'scheduleStandardShipping' => ScheduleStandardShippingAction::class,
                    'scheduleExpressShipping' => ScheduleExpressShippingAction::class,
                    'recordShipping' => RecordShippingAction::class,
                    'sendShippingNotification' => SendShippingNotificationAction::class,
                    'processRefund' => ProcessRefundAction::class,
                    'releaseInventory' => ReleaseInventoryAction::class,
                    // ... more actions
                ],
                'guards' => [
                    'canRetryPayment' => CanRetryPaymentGuard::class,
                    'requiresExpressShipping' => RequiresExpressShippingGuard::class,
                    'canCancelOrder' => CanCancelOrderGuard::class,
                    'canProcessReturn' => CanProcessReturnGuard::class,
                    // ... more guards
                ]
            ]
        );
    }
}
```

## Key Action Implementations

```php
<?php

namespace App\Actions\Order;

use App\Contexts\OrderContext;
use App\Services\PaymentService;
use App\Services\InventoryService;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Definition\EventDefinition;

class ProcessPaymentAction extends ActionBehavior
{
    public function __construct(
        private PaymentService $paymentService
    ) {}

    public function __invoke(OrderContext $context, EventDefinition $event): void
    {
        try {
            $paymentResult = $this->paymentService->charge([
                'amount' => $context->total,
                'currency' => 'USD',
                'payment_method' => $context->paymentMethod,
                'customer_id' => $context->customerId,
                'description' => "Order {$context->orderId}",
                'metadata' => [
                    'order_id' => $context->orderId,
                    'customer_email' => $context->customerEmail
                ]
            ]);

            if ($paymentResult->successful()) {
                $context->paymentId = $paymentResult->id;
                $context->processedAt = now();
                $context->addNotification('payment', 'Payment processed successfully');
                
                // Trigger success event
                event(new OrderPaymentSucceeded($context->orderId, $paymentResult));
            } else {
                $context->errorMessage = $paymentResult->error;
                $context->paymentRetries++;
                
                Log::warning('Order payment failed', [
                    'order_id' => $context->orderId,
                    'error' => $paymentResult->error,
                    'retry_count' => $context->paymentRetries
                ]);
                
                // Trigger failure event
                event(new OrderPaymentFailed($context->orderId, $paymentResult->error));
            }
        } catch (Exception $e) {
            $context->errorMessage = 'Payment processing error: ' . $e->getMessage();
            $context->paymentRetries++;
            
            Log::error('Order payment exception', [
                'order_id' => $context->orderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }
}

class ReserveInventoryAction extends ActionBehavior
{
    public function __construct(
        private InventoryService $inventoryService
    ) {}

    public function __invoke(OrderContext $context): void
    {
        $reservations = [];
        
        try {
            foreach ($context->items as $item) {
                $reservation = $this->inventoryService->reserve(
                    $item['sku'],
                    $item['quantity'],
                    $context->orderId
                );
                
                if (!$reservation->successful()) {
                    // Rollback any successful reservations
                    $this->rollbackReservations($reservations);
                    
                    $context->errorMessage = "Insufficient inventory for {$item['sku']}";
                    event(new OrderInventoryInsufficient($context->orderId, $item['sku']));
                    return;
                }
                
                $reservations[] = $reservation;
            }
            
            $context->inventoryReservations = array_map(
                fn($r) => $r->toArray(),
                $reservations
            );
            
            Log::info('Inventory reserved for order', [
                'order_id' => $context->orderId,
                'reservations' => count($reservations)
            ]);
            
            event(new OrderInventoryReserved($context->orderId, $reservations));
            
        } catch (Exception $e) {
            $this->rollbackReservations($reservations);
            $context->errorMessage = 'Inventory reservation failed: ' . $e->getMessage();
            throw $e;
        }
    }
    
    private function rollbackReservations(array $reservations): void
    {
        foreach ($reservations as $reservation) {
            try {
                $this->inventoryService->release($reservation->id);
            } catch (Exception $e) {
                Log::error('Failed to rollback inventory reservation', [
                    'reservation_id' => $reservation->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}

class StartFulfillmentAction extends ActionBehavior
{
    public function __invoke(OrderContext $context): void
    {
        // Create fulfillment tasks
        $fulfillmentTasks = collect($context->items)->map(function ($item) use ($context) {
            return [
                'order_id' => $context->orderId,
                'sku' => $item['sku'],
                'quantity' => $item['quantity'],
                'location' => $this->determinePickLocation($item['sku']),
                'priority' => $context->isPremiumCustomer() ? 'high' : 'normal'
            ];
        });
        
        // Dispatch to warehouse management system
        foreach ($fulfillmentTasks as $task) {
            WarehouseFulfillmentJob::dispatch($task)
                ->onQueue('warehouse-fulfillment');
        }
        
        $context->addNotification('fulfillment', 'Order fulfillment started');
        
        Log::info('Order fulfillment initiated', [
            'order_id' => $context->orderId,
            'tasks' => $fulfillmentTasks->count(),
            'priority' => $context->isPremiumCustomer() ? 'high' : 'normal'
        ]);
    }
    
    private function determinePickLocation(string $sku): string
    {
        // Logic to determine optimal pick location
        return 'warehouse-main'; // Simplified
    }
}
```

## Guard Implementations

```php
<?php

namespace App\Guards\Order;

use App\Contexts\OrderContext;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;

class CanRetryPaymentGuard extends GuardBehavior
{
    public function __invoke(OrderContext $context): bool
    {
        return $context->canRetryPayment();
    }
}

class CanCancelOrderGuard extends GuardBehavior
{
    public function __invoke(OrderContext $context): bool
    {
        // Can't cancel if already shipped
        $nonCancellableStates = ['shipped', 'delivered', 'completed', 'refunded'];
        
        // Check if in a cancellable state
        return !in_array($context->currentState, $nonCancellableStates) &&
               !$context->shippedAt;
    }
}

class RequiresExpressShippingGuard extends GuardBehavior
{
    public function __invoke(OrderContext $context): bool
    {
        return $context->requiresExpressShipping();
    }
}

class CanProcessReturnGuard extends GuardBehavior
{
    public function __invoke(OrderContext $context, EventDefinition $event): bool
    {
        // Must be delivered and within return window
        if (!$context->deliveredAt) {
            return false;
        }
        
        $returnWindowDays = 30;
        $returnDeadline = $context->deliveredAt->addDays($returnWindowDays);
        
        if (now()->isAfter($returnDeadline)) {
            return false;
        }
        
        // Check return policy for specific items
        $returnableItems = array_filter($context->items, function ($item) {
            return $item['returnable'] ?? true;
        });
        
        return count($returnableItems) > 0;
    }
}
```

## Event Definitions

```php
<?php

namespace App\Events\Order;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class PlaceOrderEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'PLACE_ORDER';
    }

    public function validatePayload(): array
    {
        return [
            'customer_id' => 'required|integer|exists:customers,id',
            'items' => 'required|array|min:1',
            'items.*.sku' => 'required|string|exists:products,sku',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'shipping_address' => 'required|array',
            'shipping_address.line1' => 'required|string',
            'shipping_address.city' => 'required|string',
            'shipping_address.state' => 'required|string',
            'shipping_address.zip' => 'required|string',
            'payment_method' => 'required|string',
            'shipping_method' => 'required|in:standard,express,overnight'
        ];
    }
}

class CancelOrderEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'CANCEL_ORDER';
    }

    public function validatePayload(): array
    {
        return [
            'reason' => 'required|string|max:500',
            'requested_by' => 'required|string'
        ];
    }
}

class ReturnRequestEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'RETURN_REQUESTED';
    }

    public function validatePayload(): array  
    {
        return [
            'items' => 'required|array|min:1',
            'items.*.sku' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.reason' => 'required|string',
            'return_method' => 'required|in:pickup,drop_off,mail'
        ];
    }
}
```

## Laravel Integration

### Controller

```php
<?php

namespace App\Http\Controllers;

use App\Machines\OrderProcessingMachine;
use App\Http\Requests\PlaceOrderRequest;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function store(PlaceOrderRequest $request): JsonResponse
    {
        try {
            $order = OrderProcessingMachine::create([
                'customerId' => $request->customer_id,
                'customerEmail' => $request->customer_email,
                'customerName' => $request->customer_name,
                'items' => $request->items,
                'shippingAddress' => $request->shipping_address,
                'billingAddress' => $request->billing_address ?? $request->shipping_address,
                'paymentMethod' => $request->payment_method,
                'shippingMethod' => $request->shipping_method,
                'shippingCost' => $this->calculateShippingCost($request)
            ]);

            return response()->json([
                'order_id' => $order->state->context->orderId,
                'status' => $order->state->value,
                'total' => $order->state->context->total,
                'estimated_delivery' => $this->calculateDeliveryDate($order->state->context)
            ], 201);

        } catch (Exception $e) {
            Log::error('Order creation failed', [
                'customer_id' => $request->customer_id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Order could not be created',
                'message' => $e->getMessage()
            ], 422);
        }
    }

    public function show(string $orderId): JsonResponse
    {
        $order = OrderProcessingMachine::find($orderId);

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        $context = $order->state->context;

        return response()->json([
            'order_id' => $context->orderId,
            'status' => $order->state->value,
            'customer' => [
                'id' => $context->customerId,
                'email' => $context->customerEmail,
                'name' => $context->customerName
            ],
            'items' => $context->items,
            'totals' => [
                'subtotal' => $context->subtotal,
                'tax' => $context->taxAmount,
                'shipping' => $context->shippingCost,
                'total' => $context->total
            ],
            'payment' => [
                'method' => $context->paymentMethod,
                'payment_id' => $context->paymentId,
                'status' => $this->getPaymentStatus($order->state->value)
            ],
            'shipping' => [
                'method' => $context->shippingMethod,
                'tracking_number' => $context->trackingNumber,
                'address' => $context->shippingAddress
            ],
            'timestamps' => [
                'created_at' => $context->createdAt,
                'processed_at' => $context->processedAt,
                'shipped_at' => $context->shippedAt,
                'delivered_at' => $context->deliveredAt
            ],
            'notifications' => $context->notifications
        ]);
    }

    public function cancel(string $orderId, Request $request): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500'
        ]);

        $order = OrderProcessingMachine::find($orderId);

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        try {
            $order = $order->send('CANCEL_ORDER', [
                'reason' => $request->reason,
                'requested_by' => auth()->user()->email
            ]);

            return response()->json([
                'message' => 'Order cancellation initiated',
                'order_id' => $orderId,
                'status' => $order->state->value
            ]);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'Order could not be cancelled',
                'message' => $e->getMessage()
            ], 422);
        }
    }
}
```

### Background Jobs

```php
<?php

namespace App\Jobs;

use App\Machines\OrderProcessingMachine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessOrderPaymentRetryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private string $orderId,
        private int $delayMinutes = 5
    ) {
        $this->delay($delayMinutes * 60); // Convert to seconds
    }

    public function handle(): void
    {
        $order = OrderProcessingMachine::find($this->orderId);

        if (!$order) {
            Log::warning('Payment retry job - order not found', [
                'order_id' => $this->orderId
            ]);
            return;
        }

        try {
            $order->send('RETRY_PAYMENT');
            
            Log::info('Payment retry attempted', [
                'order_id' => $this->orderId,
                'attempt' => $order->state->context->paymentRetries
            ]);
            
        } catch (Exception $e) {
            Log::error('Payment retry failed', [
                'order_id' => $this->orderId,
                'error' => $e->getMessage()
            ]);
            
            $this->fail($e);
        }
    }
}

class WarehouseFulfillmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private array $fulfillmentTask
    ) {}

    public function handle(): void
    {
        try {
            // Simulate warehouse operations
            $this->pickItems();
            $this->packItems();
            $this->generateShippingLabel();
            
            $order = OrderProcessingMachine::find($this->fulfillmentTask['order_id']);
            
            if ($order) {
                $order->send('ITEMS_PREPARED');
            }
            
        } catch (Exception $e) {
            Log::error('Warehouse fulfillment failed', [
                'task' => $this->fulfillmentTask,
                'error' => $e->getMessage()
            ]);
            
            $order = OrderProcessingMachine::find($this->fulfillmentTask['order_id']);
            if ($order) {
                $order->send('PREPARATION_FAILED', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
    
    private function pickItems(): void
    {
        // Integrate with warehouse management system
        sleep(2); // Simulate processing time
    }
    
    private function packItems(): void
    {
        // Packing logic
        sleep(1);
    }
    
    private function generateShippingLabel(): void
    {
        // Generate shipping label
        sleep(1);
    }
}
```

## Monitoring and Analytics

```php
<?php

namespace App\Console\Commands;

use App\Machines\OrderProcessingMachine;
use Illuminate\Console\Command;

class OrderAnalyticsCommand extends Command
{
    protected $signature = 'orders:analytics {period=today}';
    protected $description = 'Generate order processing analytics';

    public function handle(): int
    {
        $period = $this->argument('period');
        
        // This would typically query your machine event store
        $analytics = $this->generateAnalytics($period);
        
        $this->info("Order Processing Analytics - {$period}");
        $this->table(
            ['Metric', 'Count', 'Percentage'],
            [
                ['Total Orders', $analytics['total'], '100%'],
                ['Completed', $analytics['completed'], $analytics['completed_pct']],
                ['In Progress', $analytics['in_progress'], $analytics['in_progress_pct']],
                ['Cancelled', $analytics['cancelled'], $analytics['cancelled_pct']],
                ['Failed', $analytics['failed'], $analytics['failed_pct']],
            ]
        );
        
        $this->info("\nAverage Processing Times:");
        $this->table(
            ['Stage', 'Average Time'],
            [
                ['Validation to Payment', $analytics['avg_validation_time']],
                ['Payment to Fulfillment', $analytics['avg_payment_time']],
                ['Fulfillment to Shipping', $analytics['avg_fulfillment_time']],
                ['Total Processing Time', $analytics['avg_total_time']],
            ]
        );
        
        return 0;
    }
}
```

## Testing

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Machines\OrderProcessingMachine;
use App\Services\PaymentService;
use App\Services\InventoryService;
use Illuminate\Support\Facades\Queue;

class OrderProcessingTest extends TestCase
{
    public function test_successful_order_processing_flow()
    {
        Queue::fake();
        
        // Mock services
        $this->mock(PaymentService::class)
            ->shouldReceive('charge')
            ->andReturn((object)['successful' => true, 'id' => 'pay_123']);
            
        $this->mock(InventoryService::class)
            ->shouldReceive('reserve')
            ->andReturn((object)['successful' => true, 'id' => 'res_123']);

        $order = OrderProcessingMachine::create([
            'customerId' => 1,
            'customerEmail' => 'test@example.com',
            'items' => [
                ['sku' => 'PROD-001', 'quantity' => 2, 'price' => 50.00]
            ],
            'shippingMethod' => 'standard',
            'paymentMethod' => 'card_123'
        ]);

        // Should start in validating state
        $this->assertEquals('validating', $order->state->value);

        // Trigger validation success
        $order = $order->send('VALIDATION_SUCCESS');
        $this->assertEquals('reservingInventory', $order->state->value);

        // Trigger inventory reserved
        $order = $order->send('INVENTORY_RESERVED');
        $this->assertEquals('processingPayment', $order->state->value);

        // Trigger payment success
        $order = $order->send('PAYMENT_SUCCESS');
        $this->assertEquals('fulfillment.preparing', $order->state->value);
        
        // Verify context updates
        $this->assertEquals('pay_123', $order->state->context->paymentId);
        $this->assertNotNull($order->state->context->processedAt);
    }

    public function test_payment_failure_with_retries()
    {
        $order = OrderProcessingMachine::create([
            'customerId' => 1,
            'paymentRetries' => 0
        ]);

        // Move to payment processing state
        $order = $order->send('VALIDATION_SUCCESS');
        $order = $order->send('INVENTORY_RESERVED');

        // Trigger payment failure
        $order = $order->send('PAYMENT_FAILED');
        
        // Should move to retry state
        $this->assertEquals('paymentRetry', $order->state->value);
        $this->assertEquals(1, $order->state->context->paymentRetries);

        // After max retries, should move to failed state
        $order->state->context->paymentRetries = 3;
        $order = $order->send('PAYMENT_FAILED');
        
        $this->assertEquals('paymentFailed', $order->state->value);
    }

    public function test_order_cancellation()
    {
        $order = OrderProcessingMachine::create(['customerId' => 1]);
        
        // Move to fulfillment state
        $order = $order->send('VALIDATION_SUCCESS');
        $order = $order->send('INVENTORY_RESERVED');
        $order = $order->send('PAYMENT_SUCCESS');

        // Cancel order
        $order = $order->send('CANCEL_ORDER', [
            'reason' => 'Customer requested',
            'requested_by' => 'customer@example.com'
        ]);

        $this->assertEquals('cancelling', $order->state->value);
    }

    public function test_return_request_validation()
    {
        $order = OrderProcessingMachine::create([
            'customerId' => 1,
            'deliveredAt' => now()->subDays(10) // Delivered 10 days ago
        ]);

        // Move to shipped state
        $order->state->value = 'shipped';
        $order = $order->send('DELIVERED');
        
        $this->assertEquals('completed', $order->state->value);

        // Request return within window
        $order = $order->send('RETURN_REQUESTED', [
            'items' => [
                ['sku' => 'PROD-001', 'quantity' => 1, 'reason' => 'Defective']
            ],
            'return_method' => 'mail'
        ]);

        $this->assertEquals('returningItems', $order->state->value);
    }
}
```

## Key Concepts Demonstrated

This comprehensive order processing example showcases:

1. **Complex State Hierarchies**: Nested fulfillment states with different shipping methods
2. **Error Handling & Recovery**: Multiple error states with recovery paths
3. **Compensation Patterns**: Automatic inventory release on failures
4. **Retry Logic**: Payment retry with exponential backoff
5. **Guard Conditions**: Business rule enforcement at transition points
6. **External Integrations**: Payment services, inventory management, shipping
7. **Event Sourcing**: Complete audit trail of order lifecycle
8. **Background Processing**: Async fulfillment and notification jobs
9. **Monitoring**: Analytics and reporting capabilities
10. **Testing Strategies**: Comprehensive test coverage for all scenarios

This pattern works well for any complex business process with multiple steps, external dependencies, and error recovery requirements such as:
- Manufacturing workflows
- Document approval processes  
- Multi-step user onboarding
- Complex API integrations
- Financial transaction processing