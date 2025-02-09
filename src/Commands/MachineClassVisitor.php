<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node\Stmt\Namespace_;

class MachineClassVisitor extends NodeVisitorAbstract
{
    private array $machineClasses     = [];
    private ?string $currentNamespace = null;
    private string $currentFile;

    public function setCurrentFile(string $file): void
    {
        $this->currentFile      = $file;
        $this->machineClasses   = [];
        $this->currentNamespace = null;
    }

    public function enterNode(Node $node): mixed
    {
        if ($node instanceof Namespace_) {
            $this->currentNamespace = $node->name?->toString();
        }

        if ($node instanceof Class_ && !$node->isAbstract()) {
            $extends = $node->extends?->toString();
            if ($extends === 'Machine' || $extends === '\\Tarfinlabs\\EventMachine\\Actor\\Machine') {
                $className = $node->name->toString();
                if ($this->currentNamespace) {
                    $className = $this->currentNamespace.'\\'.$className;
                }
                $this->machineClasses[] = $className;
            }
        }

        return null;
    }

    public function getMachineClasses(): array
    {
        return $this->machineClasses;
    }
}
