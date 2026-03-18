<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tarfinlabs\EventMachine\Commands\ExportXStateCommand;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\FqcnForwardParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\RenameForwardParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardChildEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardParentEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\FullConfigForwardParentMachine;

// ═══════════════════════════════════════════════════════════════
//  XState Export with Forward Endpoints
// ═══════════════════════════════════════════════════════════════

it('E2E: machine:xstate exports forward parent endpoint machine without errors', function (): void {
    $this->artisan('machine:xstate', [
        'machine'  => ForwardParentEndpointMachine::class,
        '--stdout' => true,
    ])->assertExitCode(0);
});

it('E2E: machine:xstate includes forward metadata in invoke node', function (): void {
    Artisan::call('machine:xstate', [
        'machine'  => ForwardParentEndpointMachine::class,
        '--stdout' => true,
    ]);
    $output = Artisan::output();

    expect($output)->toContain('"forward"')
        ->and($output)->toContain('"src"')
        ->and($output)->toContain('ForwardChildEndpointMachine');
});

it('E2E: machine:xstate exports forward metadata with all Format 3 keys', function (): void {
    $machine = FullConfigForwardParentMachine::create();

    $command = new ExportXStateCommand();
    $method  = new ReflectionMethod($command, 'buildMachineNode');
    $xstate  = $method->invoke($command, $machine->definition);

    // processing state should have invoke with forward in meta
    expect($xstate['states']['processing'])->toHaveKey('invoke');

    $invoke = $xstate['states']['processing']['invoke'];
    expect($invoke['src'])->toBe('ForwardChildEndpointMachine')
        ->and($invoke['meta']['eventMachine'])->toHaveKey('forward');
});

it('E2E: machine:xstate exports rename forward parent without errors', function (): void {
    $this->artisan('machine:xstate', [
        'machine'  => RenameForwardParentMachine::class,
        '--stdout' => true,
    ])->assertExitCode(0);
});

it('E2E: machine:xstate exports FQCN forward parent without errors', function (): void {
    $this->artisan('machine:xstate', [
        'machine'  => FqcnForwardParentMachine::class,
        '--stdout' => true,
    ])->assertExitCode(0);
});

// ═══════════════════════════════════════════════════════════════
//  Config Validation with Forward Endpoints
// ═══════════════════════════════════════════════════════════════

it('E2E: machine:validate passes for forward parent endpoint machine', function (): void {
    $this->artisan('machine:validate', [
        'machine' => [class_basename(ForwardParentEndpointMachine::class)],
    ])->assertSuccessful();
});

it('E2E: machine:validate passes for full config forward parent machine', function (): void {
    $this->artisan('machine:validate', [
        'machine' => [class_basename(FullConfigForwardParentMachine::class)],
    ])->assertSuccessful();
});

it('E2E: machine:validate passes for rename forward parent machine', function (): void {
    $this->artisan('machine:validate', [
        'machine' => [class_basename(RenameForwardParentMachine::class)],
    ])->assertSuccessful();
});

it('E2E: machine:validate passes for child endpoint machine', function (): void {
    $this->artisan('machine:validate', [
        'machine' => [class_basename(ForwardChildEndpointMachine::class)],
    ])->assertSuccessful();
});
