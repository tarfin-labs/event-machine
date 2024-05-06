<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Tarfinlabs\EventMachine\Definition\StateDefinition;

class GenerateUmlCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'machine:uml {machine : The Machine path}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate machine uml';

    private array $lines  = [];
    private array $colors = [];

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $machinePath = $this->argument('machine');

        $machine       = $machinePath::create();
        $this->lines[] = '@startuml';
        $this->lines[] = 'skinparam linetype polyline';
        $this->lines[] = '
        <style>
           stateDiagram {
                     Linecolor cornflowerblue
                     LineThickness 2
                     FontStyle bold
                     FontName Helvetica Neue

                      arrow {
                       LineColor cornflowerblue
                       LineThickness 2
                     }
                   }
        </style>
        ';

        $this->lines[] = 'set namespaceSeparator none';

        $this->addTransition(from: '[*]', to: $machine->definition->initialStateDefinition->id);
        foreach ($machine->definition->stateDefinitions as $stateDefinition) {
            if ($stateDefinition->stateDefinitions !== null) {
                $this->colors[$stateDefinition->id] = '#'.substr(dechex(crc32($stateDefinition->id)), 0, 6);
            }

            $this->handleStateDefinition(stateDefinition: $stateDefinition);
        }

        foreach ($this->colors as $state => $color) {
            $this->lines[] = "state {$state}";
        }

        $this->lines[] = '@enduml';

        $filePath = str_replace(
            '\\',
            DIRECTORY_SEPARATOR,
            $machinePath
        );

        File::put(base_path(dirname($filePath).'/'.$machine->definition->root->key.'-machine.puml'), implode("\r\n", $this->lines));
    }

    private function handleStateDefinition(StateDefinition $stateDefinition): void
    {
        if ($stateDefinition->stateDefinitions !== null) {
            $this->lines[] = "state {$stateDefinition->id} {";
            $this->addTransition(from: '[*]', to: $stateDefinition->initialStateDefinition->id);
            foreach ($stateDefinition->stateDefinitions as $childStateDefinition) {
                $this->colors[$childStateDefinition->id] = $this->colors[$stateDefinition->id];
                $this->handleStateDefinition(stateDefinition: $childStateDefinition);
            }
            $this->lines[] = '}';
        }

        $this->handleTransitions(stateDefinition: $stateDefinition);

        if (!in_array("{$stateDefinition->id} : {$stateDefinition->description}", $this->lines)) {
            $this->lines[] = "{$stateDefinition->id} : {$stateDefinition->description}";
        }
    }

    private function handleTransitions(StateDefinition $stateDefinition): void
    {
        foreach ($stateDefinition->entry as $entryAction) {
            $this->lines[] = "{$stateDefinition->id} : {$entryAction}";
        }

        if ($stateDefinition->transitionDefinitions !== null) {
            foreach ($stateDefinition->transitionDefinitions as $event => $transitionDefinition) {
                $branches  = $transitionDefinition->branches ?? [];
                $eventName = str_replace('@', '', $event);

                /** @var \Tarfinlabs\EventMachine\Definition\TransitionBranch $branch */
                foreach ($branches as $branch) {

                    $this->addTransition(
                        from: $stateDefinition,
                        to: $branch->target,
                        eventName: $eventName,
                    );
                }
            }
        }
    }

    private function addTransition(
        StateDefinition|string $from,
        StateDefinition|string|null $to,
        ?string $eventName = '',
        string $direction = 'down',
        array $attributes = [],
        string $arrow = '-'
    ): void {

        $from = $from->id ?? $from;
        $to   = $to?->id ?? $to ?? $from;

        foreach ($this->colors as $id => $transitionColor) {
            if (str_starts_with($to, $id)) {
                $attributes[] = $transitionColor;
                break;
            }
        }

        $attributeString = '';
        if (!empty($attributes)) {
            $attributeString = '['.implode(',', $attributes).']';
        }

        if (empty($eventName)) {
            $this->lines[] = "{$from} -{$attributeString}$direction$arrow> {$to}";
        } else {
            $this->lines[] = "{$from} -{$attributeString}$direction$arrow> {$to} : <color:green>[{$eventName}]</color>";
        }
    }
}
