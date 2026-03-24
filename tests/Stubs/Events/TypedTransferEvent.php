<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Events;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Models\ModelA;

class TypedTransferEvent extends EventBehavior
{
    public function __construct(
        public int $amount = 0,
        public ?string $recipient = null,
        public ?ModelA $sender_model = null,
    ) {}

    public static function getType(): string
    {
        return 'TYPED_TRANSFER';
    }

    public static function rules(): array
    {
        return [
            'amount'    => ['required', 'integer', 'min:1'],
            'recipient' => ['required', 'string'],
        ];
    }
}
