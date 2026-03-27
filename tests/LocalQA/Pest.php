<?php

declare(strict_types=1);

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

// cleanTables() runs in beforeEach of each test file (not afterEach).
// afterEach cleanup was causing excessive quiet-period waits (68 tests × 500ms+)
// which slowed the suite and didn't prevent all cross-test pollution.
// The beforeEach approach is sufficient: each test starts with a clean slate.
