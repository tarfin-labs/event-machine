<?php

declare(strict_types=1);

// region machine:cache

it('caches machine discovery results with exit code 0', function (): void {
    $cachePath = $this->app->bootstrapPath('cache/machines.php');

    // Ensure clean state
    if (file_exists($cachePath)) {
        unlink($cachePath);
    }

    $this->artisan('machine:cache')
        ->expectsOutputToContain('Scanning for machine classes...')
        ->expectsOutputToContain('Machine cache written:')
        ->assertSuccessful();

    expect(file_exists($cachePath))->toBeTrue();

    // Cache file should be a valid PHP file that returns an array
    $cached = require $cachePath;
    expect($cached)->toBeArray();

    // Cleanup
    unlink($cachePath);
});

it('overwrites existing cache file on re-run', function (): void {
    $cachePath = $this->app->bootstrapPath('cache/machines.php');

    // Run twice
    $this->artisan('machine:cache')->assertSuccessful();
    $this->artisan('machine:cache')->assertSuccessful();

    expect(file_exists($cachePath))->toBeTrue();

    // Cleanup
    unlink($cachePath);
});

// endregion

// region machine:clear

it('clears the machine cache file with exit code 0', function (): void {
    $cachePath = $this->app->bootstrapPath('cache/machines.php');

    // First cache, then clear
    $this->artisan('machine:cache')->assertSuccessful();
    expect(file_exists($cachePath))->toBeTrue();

    $this->artisan('machine:clear')
        ->expectsOutputToContain('Machine cache cleared.')
        ->assertSuccessful();

    expect(file_exists($cachePath))->toBeFalse();
});

it('handles clearing when no cache file exists', function (): void {
    $cachePath = $this->app->bootstrapPath('cache/machines.php');

    // Ensure no cache exists
    if (file_exists($cachePath)) {
        unlink($cachePath);
    }

    $this->artisan('machine:clear')
        ->expectsOutputToContain('Machine cache not found (already cleared).')
        ->assertSuccessful();
});

// endregion
