<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Testing\InteractsWithMachines;

uses(
    TestCase::class,
    RefreshDatabase::class,
    InteractsWithMachines::class,
)->in(
    'Actor', 'Analysis', 'Architecture', 'Behavior', 'Commands', 'Definition',
    'E2E', 'Examples', 'Features', 'Integration', 'Jobs',
    'Models', 'Routing', 'Services', 'Support',
);

/*
|--------------------------------------------------------------------------
| Fake Cleanup
|--------------------------------------------------------------------------
|
| Reset all Fakeable trait mocks between tests. resetAllFakes() clears
| ALL faked behaviors across ALL classes (the $fakes array is shared via
| InvokableBehavior). Call it from any behavior class in afterEach().
|
| Example (add to test files that use fakes):
|   afterEach(fn() => IncrementAction::resetAllFakes());
|
*/

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

// function something()
// {
//    // ..
// }
