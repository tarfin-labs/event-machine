<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Contexts;

use Tarfinlabs\EventMachine\ContextManager;

/**
 * A catch-all typed context for tests.
 *
 * Covers all context keys used across the test suite so that
 * every inline/stub machine can reference a single class
 * instead of needing its own dedicated ContextManager subclass.
 */
class GenericContext extends ContextManager
{
    public function __construct(
        // Common scalars
        public mixed $count = 0,
        public mixed $value = null,
        public mixed $key = null,
        public mixed $status = null,
        public mixed $name = null,
        public mixed $error = null,
        public mixed $result = null,
        public mixed $decision = null,
        public mixed $action = null,
        public mixed $reason = null,
        public mixed $state = null,
        public mixed $event = null,
        public mixed $event_type = null,
        public mixed $locale = null,
        public mixed $email = null,
        public mixed $data = null,
        public mixed $custom = null,
        public mixed $some = null,
        public mixed $extra = null,
        public mixed $foo = null,
        public mixed $id = null,
        public mixed $initial = null,

        // Booleans
        public mixed $logged = false,
        public mixed $listened = false,
        public mixed $approved = false,
        public mixed $ready = false,
        public mixed $processed = false,
        public mixed $calculated = false,
        public mixed $eligible = false,
        public mixed $entered = false,
        public mixed $exited = false,
        public mixed $is_expired = false,
        public mixed $setup_complete = false,
        public mixed $sync_ran = false,
        public mixed $is_edit = false,
        public mixed $internal_transition = false,

        // Arrays
        public array $log = [],
        public array $steps = [],
        public array $values = [],
        public array $numbers = [],
        public array $counts = [],
        public array $large_context = [],
        public array $shared_array = [],
        public array $execution_order = [],

        // Order / Payment
        public mixed $order_id = null,
        public mixed $payment_id = null,
        public mixed $receipt_url = null,
        public mixed $user = null,
        public mixed $user_id = null,
        public mixed $amount = null,
        public mixed $total = null,
        public mixed $total_amount = null,
        public mixed $total_price = null,
        public mixed $unit_price = null,
        public mixed $quantity = null,
        public mixed $items_count = null,
        public mixed $card_last4 = null,
        public mixed $tckn = null,

        // Region results (parallel states)
        public mixed $region_a_result = null,
        public mixed $region_b_result = null,
        public mixed $region_c_result = null,
        public mixed $region_d_result = null,
        public mixed $region_a_ran = false,
        public mixed $region_b_ran = false,
        public mixed $region_a_event_type = null,
        public mixed $region_a_event_payload = null,
        public mixed $region_a_pid = null,
        public mixed $region_a_context_set = false,
        public mixed $region_a_wrote = false,
        public mixed $region_b_wrote = false,

        // Captured event data
        public mixed $captured_event_type = null,
        public mixed $captured_payload = null,
        public mixed $captured_actor = null,
        public mixed $done_event_type = null,
        public mixed $done_event_payload = null,
        public mixed $billing_event_type = null,
        public mixed $billing_event_payload = null,
        public mixed $timer_event_type = null,
        public mixed $timer_event_payload = null,
        public mixed $calculator_payload = null,

        // Entry / Exit / Transition counts
        public mixed $entry_count = 0,
        public mixed $exit_count = 0,
        public mixed $transition_count = 0,
        public mixed $root_entered = false,
        public mixed $root_exited = false,
        public mixed $root_entry_count = 0,
        public mixed $parent_entry_count = 0,
        public mixed $parent_listen_count = 0,

        // Scenarios / Testing
        public mixed $scenarioType = null,
        public mixed $concurrent_result = null,
        public mixed $poll_count = 0,
        public mixed $retry_count = 0,
        public mixed $retries = 0,
        public mixed $internal_retry_count = 0,

        // Payment / billing
        public mixed $payment_status = null,
        public mixed $payment_result = null,
        public mixed $inventory_status = null,
        public mixed $inventory_result = null,
        public mixed $policy_result = null,
        public mixed $protocol_result = null,
        public mixed $billing_count = 0,

        // Child delegation
        public mixed $child_decision = null,
        public mixed $child_got_price = false,
        public mixed $child_listen_count = 0,
        public mixed $received_order_id = null,

        // Listener
        public mixed $alert_sent = false,
        public mixed $approval_logged = false,
        public mixed $reviewer_notified = false,
        public mixed $seen_by_listener = false,
        public mixed $sync_listener_ran = false,
        public mixed $queued_listener_ran = false,
        public mixed $queued_listener_ran_at = null,
        public mixed $init_listened = false,
        public mixed $exit_listened = false,

        // Guard / behavior tracking
        public mixed $current_behavior_type = null,
        public mixed $guard_event_type = null,
        public mixed $guard_ran = false,
        public mixed $guard_received_event_type = null,
        public mixed $guard_received_event_payload = null,

        // Progress
        public mixed $progress_percent = null,
        public mixed $report = null,

        // Timer / heartbeat
        public mixed $heartbeat_count = 0,
        public mixed $timeout = null,
        public mixed $odd_count = 0,

        // Entry/exit tracking
        public mixed $entry_event_type = null,
        public array $entry_log = [],
        public mixed $entry_ran = false,
        public mixed $exit_ran = false,
        public mixed $final_entered = false,

        // Init tracking
        public mixed $init_event_type = null,

        // Raise tracking
        public mixed $raise_action_ran = false,
        public mixed $raised_event_type = null,

        // Model
        public mixed $model_a = null,

        // SendTo / DispatchTo
        public mixed $target_root_event_id = null,
        public mixed $target_class = null,
        public mixed $dispatching_verification = false,

        // Step results
        public mixed $step_one_result = null,
        public mixed $step_two_result = null,
        public mixed $step = null,

        // Shared scalar (parallel)
        public mixed $shared_scalar = null,

        // Scores
        public mixed $score = null,

        // Counter (distinct from count)
        public mixed $counter = 0,

        // Misc
        public mixed $allow = null,
        public mixed $updated_value = null,
        public mixed $nullable_field = null,

        // Nested context (for hasMissingContext tests)
        public mixed $settings = null,
        public mixed $deeply = null,
        public mixed $another = null,

        // Calculator
        public mixed $final_price = null,
    ) {}
}
