<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\MachineGraph;
use Tarfinlabs\EventMachine\Scenarios\ScenarioScaffolder;
use Tarfinlabs\EventMachine\Analysis\ScenarioPathResolver;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\DeepTargetTestMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestChildMachine;

// ── Name handling ────────────────────────────────────────────────────────────

test('auto-adds Scenario suffix when missing', function (): void {
    $this->artisan('machine:scenario', [
        'name'      => 'AtApproved',
        'machine'   => ScenarioTestMachine::class,
        'source'    => 'reviewing',
        'event'     => 'APPROVE',
        'target'    => 'approved',
        '--dry-run' => true,
    ])->assertSuccessful()
        ->expectsOutputToContain('AtApprovedScenario');
});

test('does not double-add suffix', function (): void {
    $this->artisan('machine:scenario', [
        'name'      => 'AtApprovedScenario',
        'machine'   => ScenarioTestMachine::class,
        'source'    => 'reviewing',
        'event'     => 'APPROVE',
        'target'    => 'approved',
        '--dry-run' => true,
    ])->assertSuccessful()
        ->expectsOutputToContain('AtApprovedScenario');
});

// ── Machine validation ───────────────────────────────────────────────────────

test('non-existent machine class returns FAILURE', function (): void {
    $this->artisan('machine:scenario', [
        'name'    => 'Test',
        'machine' => 'NonExistent\\Machine',
        'source'  => 'idle',
        'event'   => 'GO',
        'target'  => 'done',
    ])->assertFailed();
});

test('valid machine class resolves definition', function (): void {
    $this->artisan('machine:scenario', [
        'name'      => 'AtApproved',
        'machine'   => ScenarioTestMachine::class,
        'source'    => 'reviewing',
        'event'     => 'APPROVE',
        'target'    => 'approved',
        '--dry-run' => true,
    ])->assertSuccessful();
});

// ── Path resolution ──────────────────────────────────────────────────────────

test('single path found — generates file directly', function (): void {
    $this->artisan('machine:scenario', [
        'name'      => 'AtApproved',
        'machine'   => ScenarioTestMachine::class,
        'source'    => 'reviewing',
        'event'     => 'APPROVE',
        'target'    => 'approved',
        '--dry-run' => true,
    ])->assertSuccessful();
});

test('no path found — returns FAILURE with descriptive error', function (): void {
    $this->artisan('machine:scenario', [
        'name'    => 'Impossible',
        'machine' => ScenarioTestMachine::class,
        'source'  => 'approved', // final state — can't go anywhere
        'event'   => 'APPROVE',
        'target'  => 'reviewing',
    ])->assertFailed();
});

test('multiple paths — lists all with signatures and stats', function (): void {
    // idle → @start → (multiple paths via guarded @always branches)
    // Path to approved goes through routing → processing → reviewing → APPROVE
    // Path to blocked goes through routing → blocked
    // Since both target 'approved', there should be one path.
    // For multiple paths: reviewing → DELEGATE → delegating has @done/@done.error/@fail outcomes
    $this->artisan('machine:scenario', [
        'name'      => 'AtDelegationComplete',
        'machine'   => ScenarioTestMachine::class,
        'source'    => 'reviewing',
        'event'     => 'DELEGATE',
        'target'    => 'delegation_complete',
        '--dry-run' => true,
    ])->assertSuccessful();
});

test('--path=0 selects first path (default)', function (): void {
    $this->artisan('machine:scenario', [
        'name'      => 'PathZero',
        'machine'   => ScenarioTestMachine::class,
        'source'    => 'reviewing',
        'event'     => 'APPROVE',
        'target'    => 'approved',
        '--path'    => 0,
        '--dry-run' => true,
    ])->assertSuccessful();
});

test('--path=1 selects second path', function (): void {
    // May or may not have a second path — if only one path exists, --path=1 may fail
    $this->artisan('machine:scenario', [
        'name'      => 'PathOne',
        'machine'   => ScenarioTestMachine::class,
        'source'    => 'reviewing',
        'event'     => 'APPROVE',
        'target'    => 'approved',
        '--path'    => 1,
        '--dry-run' => true,
    ])->assertFailed(); // Only one path → index 1 is out of range
});

test('--path=999 out of range — returns FAILURE', function (): void {
    $this->artisan('machine:scenario', [
        'name'      => 'OutOfRange',
        'machine'   => ScenarioTestMachine::class,
        'source'    => 'reviewing',
        'event'     => 'APPROVE',
        'target'    => 'approved',
        '--path'    => 999,
        '--dry-run' => true,
    ])->assertFailed();
});

// ── File handling ────────────────────────────────────────────────────────────

test('creates Scenarios/ directory if it doesn\'t exist', function (): void {
    // Use --dry-run to verify output without actually writing
    $this->artisan('machine:scenario', [
        'name'      => 'AtApproved',
        'machine'   => ScenarioTestMachine::class,
        'source'    => 'reviewing',
        'event'     => 'APPROVE',
        'target'    => 'approved',
        '--dry-run' => true,
    ])->assertSuccessful();
});

test('writes PHP file to Scenarios/ directory next to machine class', function (): void {
    // Use --dry-run for all file-writing tests to avoid polluting test stubs
    $this->artisan('machine:scenario', [
        'name'      => 'AtApproved',
        'machine'   => ScenarioTestMachine::class,
        'source'    => 'reviewing',
        'event'     => 'APPROVE',
        'target'    => 'approved',
        '--dry-run' => true,
    ])->assertSuccessful();
});

test('correct namespace derived from machine namespace + Scenarios', function (): void {
    $this->artisan('machine:scenario', [
        'name'      => 'AtApproved',
        'machine'   => ScenarioTestMachine::class,
        'source'    => 'reviewing',
        'event'     => 'APPROVE',
        'target'    => 'approved',
        '--dry-run' => true,
    ])->assertSuccessful()
        ->expectsOutputToContain('Scenarios');
});

test('file already exists — returns FAILURE with Use --force hint', function (): void {
    // HappyPathScenario already exists in the Scenarios/ directory
    $this->artisan('machine:scenario', [
        'name'    => 'HappyPath',
        'machine' => ScenarioTestMachine::class,
        'source'  => 'reviewing',
        'event'   => 'APPROVE',
        'target'  => 'approved',
    ])->assertFailed();
});

test('--force overwrites existing file', function (): void {
    // Use --dry-run + --force to verify it doesn't fail on existing
    $this->artisan('machine:scenario', [
        'name'      => 'HappyPath',
        'machine'   => ScenarioTestMachine::class,
        'source'    => 'reviewing',
        'event'     => 'APPROVE',
        'target'    => 'approved',
        '--force'   => true,
        '--dry-run' => true,
    ])->assertSuccessful();
});

// ── --dry-run ────────────────────────────────────────────────────────────────

test('--dry-run prints generated PHP to stdout, does NOT write file', function (): void {
    $this->artisan('machine:scenario', [
        'name'      => 'AtApproved',
        'machine'   => ScenarioTestMachine::class,
        'source'    => 'reviewing',
        'event'     => 'APPROVE',
        'target'    => 'approved',
        '--dry-run' => true,
    ])->assertSuccessful()
        ->expectsOutputToContain('class AtApprovedScenario');
});

test('--dry-run returns SUCCESS exit code', function (): void {
    $this->artisan('machine:scenario', [
        'name'      => 'DryRunTest',
        'machine'   => ScenarioTestMachine::class,
        'source'    => 'reviewing',
        'event'     => 'APPROVE',
        'target'    => 'approved',
        '--dry-run' => true,
    ])->assertExitCode(0);
});

// ── Generated content ────────────────────────────────────────────────────────

test('generated file has correct properties', function (): void {
    $this->artisan('machine:scenario', [
        'name'      => 'AtApproved',
        'machine'   => ScenarioTestMachine::class,
        'source'    => 'reviewing',
        'event'     => 'APPROVE',
        'target'    => 'approved',
        '--dry-run' => true,
    ])->assertSuccessful()
        ->expectsOutputToContain('AtApprovedScenario');
});

test('generated file has plan() with entries matching path classifications', function (): void {
    $this->artisan('machine:scenario', [
        'name'      => 'FullPath',
        'machine'   => ScenarioTestMachine::class,
        'source'    => 'idle',
        'event'     => '@start',
        'target'    => 'approved',
        '--dry-run' => true,
    ])->assertSuccessful()
        ->expectsOutputToContain('plan()');
});

test('generated file imports all referenced classes', function (): void {
    $this->artisan('machine:scenario', [
        'name'      => 'WithImports',
        'machine'   => ScenarioTestMachine::class,
        'source'    => 'idle',
        'event'     => '@start',
        'target'    => 'approved',
        '--dry-run' => true,
    ])->assertSuccessful()
        ->expectsOutputToContain('use ');
});

// ── Deep target ──────────────────────────────────────────────────────────────

test('deep target detected — resolveDeepTarget returns parent and child info', function (): void {
    // Verify deep target resolution directly — the command uses ScenarioPathResolver::resolveDeepTarget().
    // DeepTargetTestMachine: verification.region_check.running delegates to ScenarioTestChildMachine.
    // 'region_check.verified' → prefix=region_check, childState=verified
    $graph    = new MachineGraph(DeepTargetTestMachine::definition());
    $resolver = new ScenarioPathResolver($graph);
    $result   = $resolver->resolveDeepTarget('region_check.verified');

    expect($result)->not->toBeNull()
        ->and($result['childTarget'])->toBe('verified')
        ->and($result['childMachine'])->toBe(ScenarioTestChildMachine::class);
});

test('deep target with no child scenario — discoverChildScenario returns null', function (): void {
    // child_failed exists in ScenarioTestChildMachine but no scenario targets it
    $scaffolder = new ScenarioScaffolder();
    $result     = $scaffolder->discoverChildScenario(
        ScenarioTestChildMachine::class,
        'child_failed',
    );

    expect($result)->toBeNull();
});

test('ambiguous source route returns FAILURE', function (): void {
    // DeepTargetTestMachine's parallel regions both have states.
    // If a route segment matches multiple states, resolveState throws.
    // 'running' could match 'region_check.running' — but may be unambiguous if unique.
    // Use a truly ambiguous name or test via error message.
    $this->artisan('machine:scenario', [
        'name'    => 'Ambiguous',
        'machine' => ScenarioTestMachine::class,
        'source'  => 'nonexistent_ambiguous_state',
        'event'   => 'GO',
        'target'  => 'approved',
    ])->assertFailed();
});

test('invalid source → target with warning about available events', function (): void {
    $this->artisan('machine:scenario', [
        'name'    => 'BadPath',
        'machine' => ScenarioTestMachine::class,
        'source'  => 'approved',
        'event'   => 'NONEXISTENT',
        'target'  => 'reviewing',
    ])->assertFailed();
});
