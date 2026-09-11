<?php
/**
 * Domain autoload integrity — catches "Class ... not found" BEFORE it can
 * ever reach production prediction generation.
 *
 * The reported production failure was:
 *
 *   FAILED: unexpected failure: Class "AIWorkforce\Sports\ConfidencePolicy" not found
 *   0 evaluated, 0 predictions, 0 rejections
 *
 * That shape — the engine dying before it evaluates a single fixture — is a
 * class-loading defect, not a betting-logic one, and the existing 351+ test
 * suites never asserted the thing that actually broke: that every class the
 * `libraries/AIWorkforce/autoload.php` map PROMISES to resolve actually does,
 * and that the odds-prediction engine's own dependency chain — the classes
 * DailyTicketService / PredictionPipeline / SportsCronService / TicketOptimizer
 * reference directly — loads and is usable.
 *
 * This suite is the build/test-time guard requirement #… ("Also add a
 * startup/test check") asks for: it fails LOUD and FAST in CI, long before a
 * cron run or a live `ticket_funnel` invocation can hit the fatal.
 */

test('domain autoload map: every mapped class/interface/trait file exists on disk', function () {
    $autoloadFile = APPPATH . 'libraries/AIWorkforce/autoload.php';
    assert_true(is_file($autoloadFile), 'application/libraries/AIWorkforce/autoload.php must exist');
    $src = file_get_contents($autoloadFile);

    // Pull the literal FQCN => relative-path pairs straight out of the map,
    // the same shape spl_autoload_register() resolves against at runtime.
    // This is a static check of the *source*, so it also catches a typo'd
    // path that class_exists() alone could not (PHP only proves the class
    // that was actually requested resolves, not every entry in the table).
    assert_true(
        (bool) preg_match_all(
            "/'((?:[A-Za-z0-9_]+\\\\\\\\)+[A-Za-z0-9_]+)'\\s*=>\\s*'([^']+)'/",
            $src,
            $matches,
            PREG_SET_ORDER
        ),
        'the autoload map must be a non-empty class => file table'
    );

    $base = APPPATH . 'libraries/AIWorkforce/';
    $missing = [];
    foreach ($matches as [, $fqcnRaw, $relPath]) {
        $fqcn = str_replace('\\\\', '\\', $fqcnRaw);
        $full = $base . $relPath;
        if (!is_file($full)) {
            $missing[] = "{$fqcn} => {$relPath}";
        }
    }
    assert_equals([], $missing, 'every autoload-mapped class must point at a file that exists: ' . implode(', ', $missing));
    assert_true(count($matches) > 200, 'sanity: the map should carry the full domain (found ' . count($matches) . ')');
});

test('domain autoload map: every mapped class/interface/trait actually loads and declares the namespace its key claims', function () {
    $autoloadFile = APPPATH . 'libraries/AIWorkforce/autoload.php';
    $src = file_get_contents($autoloadFile);
    preg_match_all(
        "/'((?:[A-Za-z0-9_]+\\\\\\\\)+[A-Za-z0-9_]+)'\\s*=>\\s*'([^']+)'/",
        $src,
        $matches,
        PREG_SET_ORDER
    );

    $failures = [];
    foreach ($matches as [, $fqcnRaw]) {
        $fqcn = str_replace('\\\\', '\\', $fqcnRaw);
        // The autoloader lazily requires the file; asking whether the symbol
        // exists is exactly what PredictionPipeline/DailyTicketService/etc.
        // do at runtime (`new ConfidencePolicy(...)`, `ConfidencePolicy::x()`),
        // so this reproduces the real failure mode instead of only checking
        // paths. autoload=false would defeat the point of the test.
        $loaded = class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn);
        if (!$loaded) {
            $failures[] = $fqcn;
        }
    }
    assert_equals(
        [],
        $failures,
        'these classes are declared in the autoload map but PHP could not load them '
        . '(this is exactly the "Class ... not found" production failure mode): '
        . implode(', ', $failures)
    );
});

/**
 * The Odds Prediction Ticket Engine's own dependency chain, named explicitly
 * so a future refactor that renames/removes one of these without updating
 * the autoload map fails HERE — in a few milliseconds — instead of in a live
 * cron run against real fixtures.
 */
test('odds prediction engine: every class the daily ticket pipeline directly depends on is loadable', function () {
    $required = [
        // The class this incident was named after — the adaptive confidence
        // gate DailyTicketService, PredictionPipeline and TicketOptimizer all
        // call via ConfidencePolicy::fromConfiguration()/toArray()/tierFor().
        \AIWorkforce\Sports\ConfidencePolicy::class,
        // The rest of the funnel this policy sits inside of, requirement's
        // own words: fixtures -> data validation -> odds -> prediction ->
        // confidence -> data quality -> value -> risk -> correlation -> ticket.
        \AIWorkforce\Sports\SportsIntelligence::class,
        \AIWorkforce\Sports\ConfigurationService::class,
        \AIWorkforce\Sports\MatchIntelligenceEngine::class,
        \AIWorkforce\Sports\FeatureEngineeringEngine::class,
        \AIWorkforce\Sports\PredictionEngine::class,
        \AIWorkforce\Sports\ConfidenceEngine::class,
        \AIWorkforce\Sports\DataQualityEngine::class,
        \AIWorkforce\Sports\ValueEngine::class,
        \AIWorkforce\Sports\RiskEngine::class,
        \AIWorkforce\Sports\CorrelationEngine::class,
        \AIWorkforce\Sports\PredictionPipeline::class,
        \AIWorkforce\Sports\DailyTicketService::class,
        \AIWorkforce\Sports\TicketOptimizer::class,
        \AIWorkforce\Sports\TicketGovernance::class,
        \AIWorkforce\Sports\DecisionRecorder::class,
        \AIWorkforce\Sports\SportsCronService::class,
        \AIWorkforce\Sports\OddsFreshnessEngine::class,
        \AIWorkforce\Sports\Providers\SportsProviderManager::class,
    ];
    $missing = array_values(array_filter($required, fn(string $c) => !class_exists($c)));
    assert_equals([], $missing, 'odds prediction engine dependency missing from autoload: ' . implode(', ', $missing));

    // The class itself must be the production implementation this incident
    // asked for: right namespace, right name, and NOT an empty/stub shell —
    // a stub would "load" (satisfying class_exists) while still crashing or
    // fabricating a fixed confidence the moment it is actually used.
    $ref = new ReflectionClass(\AIWorkforce\Sports\ConfidencePolicy::class);
    assert_equals('AIWorkforce\\Sports', $ref->getNamespaceName());
    assert_equals('ConfidencePolicy', $ref->getShortName());
    foreach (['fromConfiguration', 'tierFor', 'requiredConfidence', 'evaluate', 'toArray'] as $method) {
        assert_true($ref->hasMethod($method), "ConfidencePolicy::{$method}() must exist (production behavior, not a stub)");
    }
});

test('exactly one ConfidencePolicy implementation exists in the repository (no stale/duplicate copy)', function () {
    $found = [];
    $root = realpath(FCPATH);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if ($file->getExtension() !== 'php') continue;
        $path = $file->getPathname();
        if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) continue;
        if (str_contains($path, DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR)) continue;
        $contents = file_get_contents($path);
        if ($contents === false) continue;
        if (preg_match('/^\s*class\s+ConfidencePolicy\b/m', $contents)) {
            $found[] = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
        }
    }
    assert_equals(
        ['application/libraries/AIWorkforce/Sports/ConfidencePolicy.php'],
        $found,
        'ConfidencePolicy must be declared exactly once, at the PSR-4-consistent path the autoload map registers'
    );
});

test('ConfidencePolicy stays production-honest: no fixed 75%, no fabricated confidence, adaptive tiers stand', function () {
    // Requirement guard, independent of tests/cases/146: this incident's fix
    // must never be "silently reverted" back to a hard-coded universal floor
    // while the class technically still loads.
    $policy = \AIWorkforce\Sports\ConfidencePolicy::fromConfiguration(\AIWorkforce\Sports\ConfigurationService::defaults());
    $tiers = $policy->tiers();
    assert_true(count($tiers) >= 2, 'the policy must resolve more than one confidence band (adaptive, not a single fixed cutoff)');

    // A measured confidence must be reported back UNCHANGED — the policy only
    // judges legitimacy, it never rewrites the number.
    $verdict = $policy->evaluate(96, 68.42);
    assert_equals(68.42, $verdict['confidence'], 'measured confidence must never be altered by the policy');

    // Below the reject floor, nothing is quietly waved through regardless of
    // how high a (fabricated) confidence might be.
    $rejected = $policy->evaluate($policy->minimumDataQuality() - 1, 99.9);
    assert_false($rejected['qualified'], 'data below the configured floor must never qualify, however high the confidence figure claims to be');
});
