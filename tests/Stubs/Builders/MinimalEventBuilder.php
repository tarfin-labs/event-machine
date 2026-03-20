<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Builders;

use Tarfinlabs\EventMachine\Testing\EventBuilder;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\SimpleEvent;

class MinimalEventBuilder extends EventBuilder
{
    protected function eventClass(): string
    {
        return SimpleEvent::class;
    }

    public function withValue(int $value): static
    {
        return $this->state(['payload' => ['value' => $value]]);
    }
}
