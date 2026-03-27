<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

use Illuminate\Database\Eloquent\Factories\Factory;

abstract class MachineScenario
{
    /**
     * The machine class this scenario targets.
     */
    abstract protected function machine(): string;

    /**
     * Human-readable description shown in artisan --list and HTTP UI.
     */
    abstract protected function description(): string;

    /**
     * Ordered sequence of events to replay.
     */
    abstract protected function steps(): array;

    /**
     * Parent scenario to extend.
     * When set, the parent scenario plays first, then this scenario's steps continue.
     *
     * @return class-string<MachineScenario>|null
     */
    protected function parent(): ?string
    {
        return null;
    }

    /**
     * Expected starting state for mid-flight scenarios.
     * When set, playOn() validates the machine is in this state before replaying.
     * When null (default), the scenario creates a new machine from initial state.
     */
    protected function from(): ?string
    {
        return null;
    }

    /**
     * Default parameters — overridable at runtime via play(['key' => 'value']).
     */
    protected function defaults(): array
    {
        return [];
    }

    /**
     * Stub definitions for external dependencies.
     *
     * Keys are behavior/service class names.
     * Values depend on the stub type:
     *   Guard class    => bool (predetermined return value)
     *   Action class   => array (data injected instead of external call)
     *   Service class  => array<method => return_value>
     *
     * @return array<class-string, mixed>
     */
    protected function arrange(): array
    {
        return [];
    }

    /**
     * Eloquent model factories to run before event replay.
     * Keys are reference names (used in steps via $this->model('name')).
     * Values are Factory instances or closures that return a Model.
     * Created in declaration order — later models can reference earlier ones.
     *
     * @return array<string, Factory|callable>
     */
    protected function models(): array
    {
        return [];
    }

    // ─── Step Builders (used inside steps()) ────────────────────────

    protected function send(string $eventType, array $payload = []): ScenarioStep
    {
        return ScenarioStep::send($eventType, $payload);
    }

    protected function child(string $machineClass): ChildScenarioStep
    {
        return new ChildScenarioStep($machineClass);
    }

    // ─── Hydration (called by ScenarioPlayer) ───────────────────────

    private array $resolvedParams = [];
    private array $createdModels  = [];

    /**
     * @internal Called by ScenarioPlayer before models() and steps().
     */
    public function hydrate(array $params, array $models = []): void
    {
        $this->resolvedParams = array_merge($this->defaults(), $params);
        $this->createdModels  = $models;
    }

    /**
     * @internal Called by ScenarioPlayer after each model is created.
     */
    public function addModel(string $name, mixed $model): void
    {
        $this->createdModels[$name] = $model;
    }

    // ─── Parameter & Model Access ───────────────────────────────────

    protected function param(string $key, mixed $default = null): mixed
    {
        return $this->resolvedParams[$key] ?? $default;
    }

    protected function model(string $name): mixed
    {
        return $this->createdModels[$name]
            ?? throw new \RuntimeException("Model [{$name}] not found. Define it in models().");
    }

    // ─── Execution ──────────────────────────────────────────────────

    public static function play(array $params = []): ScenarioResult
    {
        $scenario = new static();

        return resolve(ScenarioPlayer::class)->play($scenario, $params);
    }

    /**
     * Play this scenario on an existing machine instance (mid-flight).
     * Validates from() state if defined.
     */
    public static function playOn(string $machineId, array $params = []): ScenarioResult
    {
        $scenario = new static();

        return resolve(ScenarioPlayer::class)->play($scenario, $params, machineId: $machineId);
    }

    // ─── Introspection (used by artisan --list, HTTP describe) ──────

    public function getMachine(): string
    {
        return $this->machine();
    }

    public function getDescription(): string
    {
        return $this->description();
    }

    public function getParent(): ?string
    {
        return $this->parent();
    }

    public function getFrom(): ?string
    {
        return $this->from();
    }

    public function getDefaults(): array
    {
        return $this->defaults();
    }

    /**
     * @internal
     */
    public function getSteps(): array
    {
        return $this->steps();
    }

    /**
     * @internal
     */
    public function getModels(): array
    {
        return $this->models();
    }

    /**
     * @internal
     */
    public function getArrange(): array
    {
        return $this->arrange();
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function getCreatedModels(): array
    {
        return $this->createdModels;
    }
}
