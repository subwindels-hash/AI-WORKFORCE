<?php
/**
 * Suite hygiene — every case file must stand on its own.
 *
 * Tools::tests() loads tests/cases/*.php in sorted order, so a case could
 * call a helper defined in a LOWER-numbered sibling and still pass in a
 * full run. Fifteen files had drifted into exactly that: 39-adaptive-
 * learning.php died with "Call to undefined function teacher_profile()"
 * the moment it was run with AI_WORKFORCE_TEST_FILTER, because the helper
 * lived in 36-language-teacher.php.
 *
 * That is a reporting failure, not a cosmetic one. A filtered run is how
 * anyone debugs a single case, and it was reporting failures that had
 * nothing to do with the code under test — while a genuine break in those
 * files could hide behind a sibling that happened to load first. Renaming
 * or deleting a case file could also break unrelated suites.
 *
 * This case pins the fix: a file that USES a cross-file helper must
 * require_once the file that DEFINES it.
 */

/** Helpers every case may assume: the framework and shared bootstrap. */
function fx155_builtin_helpers(): array
{
    $builtin = [];
    foreach (['framework.php', 'bootstrap.php'] as $shared) {
        $path = TESTSPATH . $shared;
        if (!is_file($path)) continue;
        if (preg_match_all('/^\s*function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', (string) file_get_contents($path), $m)) {
            foreach ($m[1] as $name) $builtin[strtolower($name)] = true;
        }
    }
    return $builtin;
}

test('suite isolation: no case depends on a helper defined in another case file', function () {
    $files = glob(TESTSPATH . 'cases/*.php') ?: [];
    assert_true(count($files) > 100, 'the case files were found');

    // Map every case-defined function/class to the file that declares it.
    $definedIn = [];
    $builtinGuarded = [];
    $sources = [];
    foreach ($files as $file) {
        $src = (string) file_get_contents($file);
        $sources[$file] = $src;
        if (preg_match_all('/^\s*function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', $src, $m)) {
            foreach ($m[1] as $name) {
                // Declared behind if (!function_exists('x')) — a deliberate
                // shared fallback, present whoever loads first. Not a
                // load-order dependency.
                if (str_contains($src, "function_exists('" . $name . "'")) {
                    $builtinGuarded[strtolower($name)] = true;
                    continue;
                }
                $definedIn[strtolower($name)][] = $file;
            }
        }
        if (preg_match_all('/^\s*(?:final\s+|abstract\s+)?class\s+([a-zA-Z_][a-zA-Z0-9_]*)/m', $src, $m)) {
            foreach ($m[1] as $name) $definedIn['class:' . strtolower($name)][] = $file;
        }
    }

    $builtin = fx155_builtin_helpers();
    $violations = [];

    foreach ($files as $file) {
        // This case names helpers inside its own assertion strings.
        if (basename($file) === basename(__FILE__)) continue;
        $src = $sources[$file];
        // Which sibling case files does this one pull in, directly or
        // transitively? Requiring 36 (which itself requires 35) genuinely
        // makes 35's helpers available, so the chain must be followed.
        $required = [];
        $queue = [$file];
        $seen = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($seen[$current])) continue;
            $seen[$current] = true;
            $body = $sources[$current] ?? null;
            if ($body === null) continue;
            if (preg_match_all("/require(?:_once)?\s*[^;]*?['\"]([^'\"]*\/)?([0-9]+-[a-z0-9-]+\.php)['\"]/i", $body, $m)) {
                foreach ($m[2] as $name) {
                    $target = TESTSPATH . 'cases/' . $name;
                    $required[$target] = true;
                    $queue[] = $target;
                }
            }
        }

        // Every function call and `new Class` in the file.
        $used = [];
        if (preg_match_all('/(?<![\$>:\w])([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $src, $m)) {
            foreach ($m[1] as $name) $used[strtolower($name)] = $name;
        }
        if (preg_match_all('/\bnew\s+([a-zA-Z_][a-zA-Z0-9_]*)/', $src, $m)) {
            foreach ($m[1] as $name) $used['class:' . strtolower($name)] = $name;
        }

        foreach ($used as $key => $original) {
            if (isset($builtin[$key])) continue;              // framework/bootstrap
            if (isset($builtinGuarded[$key])) continue;       // guarded shared fallback
            if (!isset($definedIn[$key])) continue;           // language/vendor/domain symbol
            $owners = $definedIn[$key];
            if (in_array($file, $owners, true)) continue;     // defined right here

            // Satisfied when ANY declaring file is explicitly required.
            $satisfied = false;
            foreach ($owners as $owner) {
                if (isset($required[$owner])) { $satisfied = true; break; }
            }
            // A guarded redefinition (if (!function_exists(...))) in this
            // file is its own safety net.
            if (!$satisfied && str_contains($src, "function_exists('" . $original . "'")) $satisfied = true;

            if (!$satisfied) {
                $violations[] = basename($file) . ' uses ' . $original
                    . '() from ' . basename($owners[0]) . ' without requiring it';
            }
        }
    }

    assert_equals([], $violations, "case files must require the siblings whose helpers they call:\n  - "
        . implode("\n  - ", array_slice($violations, 0, 25)));
});

test('suite isolation: every case file registers at least one test', function () {
    // A file that silently registers nothing (parse slip, helper-only file
    // that lost its cases) would shrink the suite without any failure.
    $files = glob(TESTSPATH . 'cases/*.php') ?: [];
    $empty = [];
    foreach ($files as $file) {
        $src = (string) file_get_contents($file);
        // test() directly, or run() from bootstrap.php which delegates to it.
        if (!preg_match('/^\s*test\(/m', $src) && !preg_match('/^\s*run\(/m', $src)) {
            $empty[] = basename($file);
        }
    }
    assert_equals([], $empty, 'these case files contribute no tests: ' . implode(', ', $empty));
});
