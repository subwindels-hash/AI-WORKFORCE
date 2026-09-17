<?php
/**
 * Release integrity: `application-deployment.zip` is the artifact production is
 * deployed from (docs/CPANEL_DEPLOYMENT.md — upload and extract with the cPanel
 * File Manager, no terminal, no git pull). Source changes therefore only reach
 * the deployed site when the archive is rebuilt: a merge that edits release
 * files without rebuilding it ships an application that keeps the OLD behaviour
 * while the repository says otherwise.
 *
 * `tools/build_deployment_zip.py --check` is the source of truth for this rule
 * (and for the exclusion list mirrored here — keep the two in sync). This case
 * makes the same check part of the suite every change already runs, so a stale
 * bundle fails the tests instead of silently reaching cPanel.
 */

/** Mirror of tools/build_deployment_zip.py ROOT_FILES. */
const CI161_ROOT_FILES = ['.env.example', '.gitignore', '.htaccess', 'index.php', 'error500.html'];

/** Mirror of tools/build_deployment_zip.py RELEASE_DIRECTORIES. */
const CI161_RELEASE_DIRECTORIES = ['application', 'assets', 'database', 'docs', 'runtime', 'system'];

/** Mirror of tools/build_deployment_zip.py EXCLUDED_DIRECTORY_NAMES. */
const CI161_EXCLUDED_DIRECTORIES = ['.git', '.next', '.venv', '__pycache__', 'build', 'coverage', 'dist', 'node_modules', 'out'];

/** Directories whose local runtime state must never ship, and what may ship instead. */
const CI161_PLACEHOLDER_ONLY = [
    'application/data/' => ['.gitkeep'],
    'runtime/sessions/' => ['.gitkeep'],
    'assets/uploads/avatars/' => ['.gitkeep', 'index.html', '.htaccess'],
    'application/cache/' => ['.gitkeep', 'index.html', '.htaccess'],
];

/** @return bool whether a repo-relative path belongs in the release archive. */
function ci161_is_release_file(string $relative): bool
{
    foreach (explode('/', $relative) as $segment) {
        if (in_array($segment, CI161_EXCLUDED_DIRECTORIES, true)) return false;
    }
    $name = basename($relative);
    if ($name === '.DS_Store' || $relative === 'application-deployment.zip') return false;
    if (preg_match('/\.(sqlite|log|pyc|tsbuildinfo)$/', $name) === 1 || str_ends_with($name, '.sqlite-journal')) return false;
    foreach (CI161_PLACEHOLDER_ONLY as $prefix => $allowed) {
        if (str_starts_with($relative, $prefix)) return in_array($name, $allowed, true);
    }
    return true;
}

/** @return string[] every release file, repo-relative and sorted. */
function ci161_release_files(): array
{
    $files = [];
    foreach (CI161_ROOT_FILES as $name) {
        if (is_file(FCPATH . $name)) $files[] = $name;
    }
    foreach (CI161_RELEASE_DIRECTORIES as $directory) {
        $base = rtrim(FCPATH, '/\\') . '/' . $directory;
        if (!is_dir($base)) continue;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if (!$entry->isFile()) continue;
            $relative = $directory . '/' . str_replace('\\', '/', substr($entry->getPathname(), strlen($base) + 1));
            if (ci161_is_release_file($relative)) $files[] = $relative;
        }
    }
    sort($files);
    return array_values(array_unique($files));
}

/** @return string[] every directory the archive must carry, with a trailing slash. */
function ci161_release_directories(array $files): array
{
    $directories = [];
    foreach ($files as $file) {
        $parent = dirname($file);
        while ($parent !== '.' && $parent !== '/' && $parent !== '') {
            $directories[$parent . '/'] = true;
            $parent = dirname($parent);
        }
    }
    $names = array_keys($directories);
    sort($names);
    return $names;
}

/** @return array{0: ZipArchive, 1: string[], 2: string[]} open archive plus its file/directory entries. */
function ci161_open_archive(): array
{
    assert_true(class_exists('ZipArchive'), 'ZipArchive is available in this PHP runtime');
    $archive = rtrim(FCPATH, '/\\') . '/application-deployment.zip';
    assert_true(is_file($archive), 'application-deployment.zip exists at the repository root');

    $zip = new ZipArchive();
    assert_equals(true, $zip->open($archive), 'open application-deployment.zip');

    $files = [];
    $directories = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) continue;
        if (str_ends_with($name, '/')) $directories[] = $name; else $files[] = $name;
    }
    sort($files);
    sort($directories);
    return [$zip, $files, $directories];
}

function ci161_show(array $names): string
{
    $shown = array_slice($names, 0, 8);
    return implode(', ', $shown) . (count($names) > count($shown) ? sprintf(' (+%d more)', count($names) - count($shown)) : '');
}

test('deployment archive carries exactly the release source files', function () {
    if (!class_exists('ZipArchive')) return;

    $expected = ci161_release_files();
    [$zip, $bundledFiles, $bundledDirectories] = ci161_open_archive();
    try {
        $missing = array_values(array_diff($expected, $bundledFiles));
        assert_equals([], $missing, 'files missing from application-deployment.zip: ' . ci161_show($missing)
            . ' — rebuild it with: python3 tools/build_deployment_zip.py');

        // An entry with no source file is a release of code that no longer exists.
        $orphaned = array_values(array_diff($bundledFiles, $expected));
        assert_equals([], $orphaned, 'archive carries files that are not release files: ' . ci161_show($orphaned));

        $expectedDirectories = ci161_release_directories($expected);
        $missingDirectories = array_values(array_diff($expectedDirectories, $bundledDirectories));
        assert_equals([], $missingDirectories, 'directory entries missing from the archive: ' . ci161_show($missingDirectories));

        return ['msg' => count($expected) . ' release files verified'];
    } finally {
        $zip->close();
    }
});

test('deployment archive is fresh: every bundled file matches its source', function () {
    if (!class_exists('ZipArchive')) return;

    [$zip, $bundledFiles] = ci161_open_archive();
    try {
        $stale = [];
        foreach ($bundledFiles as $name) {
            $source = rtrim(FCPATH, '/\\') . '/' . $name;
            $bundled = $zip->getFromName($name);
            if (!is_file($source) || !is_string($bundled) || md5_file($source) !== md5($bundled)) {
                $stale[] = $name;
            }
        }
        // This is the failure that matters: the repository has moved on while the
        // artifact a cPanel upload installs still contains the previous release.
        assert_equals([], $stale, 'application-deployment.zip is stale, it does not match the working tree: '
            . ci161_show($stale) . ' — rebuild it with: python3 tools/build_deployment_zip.py');

        return ['msg' => count($bundledFiles) . ' bundled files are current'];
    } finally {
        $zip->close();
    }
});

// The last two release changes (#148 configurable intraday ticket refresh, #149
// 5.0 odds floor + live-match discovery) each shipped a stale archive. Assert
// the properties that went missing ships, so a rebuild that reverts them fails.
test('the shipped bundle carries the current release behaviour', function () {
    if (!class_exists('ZipArchive')) return;

    $expectations = [
        // PR #149 — absolute 5.0 combined-odds floor.
        'application/libraries/AIWorkforce/Sports/ConfigurationService.php' => 'MIN_TARGET_ODDS_FLOOR',
        'application/libraries/AIWorkforce/Sports/TicketOptimizer.php' => 'MIN_TARGET_ODDS_FLOOR',
        'application/libraries/AIWorkforce/Sports/TicketGovernance.php' => 'MIN_TARGET_ODDS_FLOOR',
        // PR #149 — live-match discovery cadence and forced "Run now" polls.
        'application/libraries/AIWorkforce/Sports/LiveScoreService.php' => 'LIVE_DISCOVERY_SECONDS',
        // PR #148/#149 — every ticket click regenerates from current odds.
        'application/views/sports/index.php' => 'name="refresh" value="1"',
        'application/views/sports/tickets.php' => 'name="refresh" value="1"',
        // PR #148 — the admin cron screen exposes the ticket refresh interval.
        'application/views/admin/cron.php' => 'refresh',
    ];

    [$zip, $bundledFiles] = ci161_open_archive();
    try {
        foreach ($expectations as $name => $needle) {
            assert_in_array($name, $bundledFiles, 'archive contains ' . $name);
            $bundled = $zip->getFromName($name);
            assert_true(is_string($bundled), 'read ' . $name . ' from the archive');
            assert_contains($needle, $bundled, $name . ' in the archive carries ' . $needle);
        }
        return ['msg' => count($expectations) . ' release markers verified in the bundle'];
    } finally {
        $zip->close();
    }
});
