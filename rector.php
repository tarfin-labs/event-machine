<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;
use Rector\Set\ValueObject\SetList;
use RectorLaravel\Set\LaravelSetList;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use RectorLaravel\Rector\StaticCall\CarbonToDateFacadeRector;
use Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector;
use Rector\TypeDeclaration\Rector\Closure\ClosureReturnTypeRector;
use RectorLaravel\Rector\FuncCall\SleepFuncToSleepStaticCallRector;
use RectorLaravel\Rector\StaticCall\DispatchToHelperFunctionsRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use RectorLaravel\Rector\ClassMethod\MakeModelAttributesAndScopesProtectedRector;

return RectorConfig::configure()
    ->withPhpVersion(PhpVersion::PHP_83)

    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/tests',
    ])

    ->withSets([
        LevelSetList::UP_TO_PHP_83,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
        SetList::TYPE_DECLARATION,
        SetList::PRIVATIZATION,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
    ])

    ->withSkip([
        // Unstable or unwanted rules
        ClosureToArrowFunctionRector::class,
        ArrayToFirstClassCallableRector::class,
        ClassPropertyAssignToConstructorPromotionRector::class,
        CarbonToDateFacadeRector::class,
        MakeModelAttributesAndScopesProtectedRector::class,

        // tests/ only. Rector has no inline skip -- @noRector was removed in 0.15 as unreliable
        // (rectorphp/rector-src#3148) -- so a deliberate shape in a test is protected here or
        // not at all. Everything else it wanted to delete in tests/ turned out to be genuinely
        // dead, and was deleted rather than skipped.

        // Rewrites Job::dispatch(named: args) into dispatch(new \Fully\Qualified\Job(...)) on a
        // single line: the named arguments are what make these call sites readable.
        DispatchToHelperFunctionsRector::class => [__DIR__.'/tests'],

        // LocalQA sleeps are deliberate waits for negative assertions -- you cannot wait for the
        // absence of an event (see .claude/rules/localqa-setup.md). A fakeable Sleep::for() is a
        // different thing, and faking it would erase the wait these tests depend on.
        SleepFuncToSleepStaticCallRector::class => [__DIR__.'/tests'],

        // Pest's expect() is DECLARED to return Pest\Mixins\Expectation but returns
        // Pest\Expectation, so an inferred return type on a closure that ends in expect() is a
        // TypeError at call time. Measured: two `->each(fn (...) => expect(...))` sites in
        // ScenarioDiscoveryTest failed this way, and neither pint nor phpstan saw it -- only the
        // suite did. A return type on a test closure is not worth that failure mode.
        AddArrowFunctionReturnTypeRector::class => [__DIR__.'/tests'],
        ClosureReturnTypeRector::class          => [__DIR__.'/tests'],
    ]);
