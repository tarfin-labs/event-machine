<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;

/*
|--------------------------------------------------------------------------
| LocalQA Global Hooks
|--------------------------------------------------------------------------
|
| After every LocalQA test, drain queues and wait for in-flight jobs to
| finish. This prevents one test's async side effects from bleeding into
| the next test — the primary source of flaky LocalQA assertions.
|
*/

afterEach(function (): void {
    LocalQATestCase::cleanTables();
});
