<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

class ContextDefinition
{
    public function __construct(
        protected array $data = [],
    ) {
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
