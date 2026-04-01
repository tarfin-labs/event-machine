# Weighted Path Resolution for Scenario Scaffolding

> **Status:** Upcoming — not scheduled for implementation yet.

## Problem

`ScenarioPathResolver` uses BFS (unweighted) to find the shortest path from source to target. When multiple paths have the **same hop count**, BFS picks arbitrarily. This can produce scaffolds that route through complex delegation/parallel states when a simpler path through interactive states exists.

Example — two paths from `pending` to `approved`, both 4 hops:

```
Path A: pending → eligibility → verification (PARALLEL) → review → approved
Path B: pending → eligibility → manual_check (INTERACTIVE) → review → approved
```

BFS may return Path A (parallel + delegation) when Path B (simpler, no child machines) would produce a much simpler scaffold.

## Solution: Weighted Dijkstra

Replace BFS queue (`array_shift`, FIFO) with a priority queue sorted by accumulated weight. Each state classification gets a cost:

| Classification | Weight | Rationale |
|---------------|--------|-----------|
| TRANSIENT | 0 | Free — machine passes through automatically |
| INTERACTIVE | 1 | Normal — one event needed |
| DELEGATION | 3 | Expensive — needs child/job outcome in plan() |
| PARALLEL | 5 | Most complex — multiple regions, guard overrides |
| FINAL | 0 | Terminal — no traversal cost |

### Algorithm Change

```php
// Before (BFS — FIFO queue):
$queue = [[$startState, $path, $visited]];
[$current, $path, $visited] = array_shift($queue);  // FIFO

// After (Dijkstra — priority queue):
$queue = new SplPriorityQueue();
$queue->insert([$startState, $path, $visited], 0);   // priority = -cost (SplPriorityQueue is max-heap)
[$current, $path, $visited] = $queue->extract();      // lowest cost first
```

Each enqueue adds the classification weight:

```php
$nextCost = $currentCost + match ($classification) {
    StateClassification::TRANSIENT   => 0,
    StateClassification::INTERACTIVE => 1,
    StateClassification::DELEGATION  => 3,
    StateClassification::PARALLEL    => 5,
    StateClassification::FINAL       => 0,
};
$queue->insert([$nextState, $newPath, $newVisited], -$nextCost);
```

### Impact

- `resolve()` returns the **lowest-cost** path (simplest scaffold)
- `resolveAll()` returns **all paths sorted by cost** (simplest first)
- Backward compatible — unweighted BFS is Dijkstra with all weights = 1
- Existing tests pass unchanged (shortest-hop paths are typically also lowest-cost)

### Configuration (Optional)

Weights could be configurable via `config/machine.php`:

```php
'scenarios' => [
    'path_weights' => [
        'transient'   => 0,
        'interactive' => 1,
        'delegation'  => 3,
        'parallel'    => 5,
    ],
],
```

Default weights are sensible for most machines. Custom weights useful when a project has lightweight delegations (weight 1) or complex interactive chains (weight 2).

## Scope

- `ScenarioPathResolver::bfs()` → `dijkstra()` (rename + priority queue)
- `ScenarioPathResolver::resolveAll()` → results sorted by total weight
- `ScenarioPath` — add `totalWeight` property
- `machine:scenario` command — show path weight in multi-path selection

## When to Implement

When users report that `machine:scenario` generates unnecessarily complex scaffolds for machines with equidistant alternative paths. Current BFS is correct for most practical machines.

## References

- [Dijkstra's Algorithm — Wikipedia](https://en.wikipedia.org/wiki/Dijkstra%27s_algorithm)
- [XState `@xstate/graph` — getShortestPaths() uses Dijkstra with weight=1](https://stately.ai/docs/xstate-graph)
- [Alur & Yannakakis (2003) — Formal Analysis of Hierarchical State Machines](https://www.cis.upenn.edu/~alur/Zohar03.pdf)
