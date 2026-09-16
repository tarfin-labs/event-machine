<?php

declare(strict_types=1);

/**
 * CRAP (Change Risk Anti-Patterns) gate.
 *
 *     CRAP(m) = comp(m)^2 * (1 - cov(m)/100)^3 + comp(m)
 *
 * PHPUnit already writes a `crap` attribute on every method in the clover
 * report, so this script adds no measurement cost — it only reads what the
 * coverage run produced and turns it into a pass/fail.
 *
 * The gate enforces the zero-coverage axis of the classic CRAP > 30 line.
 * Solving CRAP(comp, 0%) = comp^2 + comp > 30 gives comp >= 5, so the rule
 * "a method with 5+ branches may not be completely untested" IS CRAP > 30
 * restricted to cov = 0. That restriction is deliberate: it keeps the gate
 * free of a grandfathered exception list, which cannot rot.
 *
 * Methods that genuinely cannot be tested are excluded the standard way —
 * mark them @codeCoverageIgnore and PHPUnit drops them from the report.
 *
 * Usage:  php scripts/crap.php [path/to/clover.xml]
 */
const CLOVER_DEFAULT = 'build/logs/clover.xml';

/** Complexity at or above which a completely uncovered method fails the gate. */
const MIN_COMPLEXITY = 5;

/** Informational only: the classic CRAP threshold, reported but not enforced. */
const CRAP_THRESHOLD = 30;

$cloverPath = $argv[1] ?? CLOVER_DEFAULT;
$isCi       = getenv('GITHUB_ACTIONS') === 'true';

// ── The report must exist and must describe the current source ──────────────
// A gate that passes on a stale or missing report is worse than no gate.

if (!is_file($cloverPath)) {
    fwrite(STDERR, "CRAP gate: coverage report not found at {$cloverPath}\n");
    fwrite(STDERR, "Run `composer test:coverage` first (requires pcov or xdebug).\n");

    exit(1);
}

$cloverTime = (int) filemtime($cloverPath);
$staleFiles = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator('src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() === 'php' && $file->getMTime() > $cloverTime) {
        $staleFiles[] = $file->getPathname();
    }
}

if ($staleFiles !== []) {
    fwrite(STDERR, sprintf(
        "CRAP gate: %s predates %d source file(s), e.g. %s\n",
        $cloverPath,
        count($staleFiles),
        $staleFiles[0],
    ));
    fwrite(STDERR, "Re-run `composer test:coverage` so the report matches the code.\n");

    exit(1);
}

// ── Parse ───────────────────────────────────────────────────────────────────

$xml = simplexml_load_file($cloverPath);

if ($xml === false) {
    fwrite(STDERR, "CRAP gate: {$cloverPath} is not readable as XML\n");

    exit(1);
}

$root    = getcwd().DIRECTORY_SEPARATOR;
$methods = [];

foreach ($xml->xpath('//file') ?? [] as $file) {
    $path  = str_replace($root, '', (string) $file['name']);
    $lines = [];

    foreach ($file->line as $line) {
        $lines[] = [
            'type'       => (string) $line['type'],
            'name'       => (string) $line['name'],
            'num'        => (int) $line['num'],
            'complexity' => (int) $line['complexity'],
            'crap'       => (float) $line['crap'],
            'count'      => (int) $line['count'],
        ];
    }

    foreach ($lines as $index => $line) {
        if ($line['type'] !== 'method') {
            continue;
        }

        // A method's statements are the lines up to the next method marker.
        $covered = $total = 0;

        for ($next = $index + 1; $next < count($lines); $next++) {
            if ($lines[$next]['type'] === 'method') {
                break;
            }

            $total++;

            if ($lines[$next]['count'] > 0) {
                $covered++;
            }
        }

        // No statements means no body to cover (abstract, interface, empty).
        if ($total === 0) {
            continue;
        }

        $methods[] = [
            'file'       => $path,
            'name'       => $line['name'],
            'line'       => $line['num'],
            'complexity' => $line['complexity'],
            'covered'    => $covered,
            'coverage'   => (float) ($covered / $total * 100),
            'crap'       => $line['crap'],
        ];
    }
}

if ($methods === []) {
    fwrite(STDERR, "CRAP gate: {$cloverPath} contains no methods — is the report empty?\n");

    exit(1);
}

// ── Evaluate ────────────────────────────────────────────────────────────────

$violations = array_values(array_filter(
    $methods,
    static fn (array $m): bool => $m['covered'] === 0 && $m['complexity'] >= MIN_COMPLEXITY,
));

usort($violations, static fn (array $a, array $b): int => $b['crap'] <=> $a['crap']);

$overThreshold = array_values(array_filter(
    $methods,
    static fn (array $m): bool => $m['crap'] > CRAP_THRESHOLD,
));

usort($overThreshold, static fn (array $a, array $b): int => $b['crap'] <=> $a['crap']);

printf(
    "CRAP gate: %d methods analysed, %d over CRAP %d (informational), %d untested with complexity >= %d\n",
    count($methods),
    count($overThreshold),
    CRAP_THRESHOLD,
    count($violations),
    MIN_COMPLEXITY,
);

if ($violations === []) {
    echo "PASS — no branchy method is completely untested.\n";
}

foreach ($violations as $m) {
    $message = sprintf(
        '%s::%s has complexity %d and no test coverage at all (CRAP %.0f). Cover it, split it, or mark it @codeCoverageIgnore.',
        basename($m['file'], '.php'),
        $m['name'],
        $m['complexity'],
        $m['crap'],
    );

    printf("  FAIL  %s:%d  %s\n", $m['file'], $m['line'], $message);

    if ($isCi) {
        printf("::error file=%s,line=%d,title=CRAP::%s\n", $m['file'], $m['line'], $message);
    }
}

// ── Job summary: keep the complexity half of CRAP visible without gating it ──

$summaryPath = getenv('GITHUB_STEP_SUMMARY');

if (is_string($summaryPath) && $summaryPath !== '') {
    $summary = sprintf(
        "## CRAP report\n\n%d methods analysed — %d failing, %d over CRAP %d.\n\n",
        count($methods),
        count($violations),
        count($overThreshold),
        CRAP_THRESHOLD,
    );
    $summary .= "| CRAP | complexity | coverage | method |\n|---:|---:|---:|---|\n";

    foreach (array_slice($overThreshold, 0, 15) as $m) {
        $summary .= sprintf(
            "| %.0f | %d | %.1f%% | `%s::%s` |\n",
            $m['crap'],
            $m['complexity'],
            $m['coverage'],
            basename($m['file'], '.php'),
            $m['name'],
        );
    }

    file_put_contents($summaryPath, $summary, FILE_APPEND);
}

exit($violations === [] ? 0 : 1);
