<?php
declare(strict_types=1);

/**
 * Modo CMS — translation audit.
 *
 * Scans PHP files for literal __() / _e() keys and compares them with every
 * languages/*.json dictionary. Exits with status 1 when a key is missing or
 * empty, so it can be used in CI or before a release.
 *
 * Usage:
 *   php tools/i18n-audit.php
 *   php tools/i18n-audit.php --json
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__);
$jsonOutput = in_array('--json', $argv, true);

/** Dynamic key prefixes that are built at runtime, not translation keys. */
$ignorePrefixes = ['cc_', 'mf_ftype_', 'mf_st_'];

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$keys = [];
foreach ($files as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $path = str_replace('\\', '/', $file->getPathname());
    if (str_contains($path, '/admin/assets/vendor/')
        || str_contains($path, '/.git/')
        || str_contains($path, '/.build/')
    ) {
        continue;
    }

    $tokens = token_get_all((string) file_get_contents($path));
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING) {
            continue;
        }

        $name = strtolower($tokens[$i][1]);
        if (!in_array($name, ['__', '_e'], true)) {
            continue;
        }

        $j = $i + 1;
        while ($j < $count && (is_array($tokens[$j]) ? $tokens[$j][0] === T_WHITESPACE : $tokens[$j] !== '(')) {
            $j++;
        }
        if ($j >= $count || $tokens[$j] !== '(') {
            continue;
        }

        $j++;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $count || !is_array($tokens[$j]) || $tokens[$j][0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        $raw = $tokens[$j][1];
        $value = $raw[0] === "'"
            ? str_replace(["\\\\", "\\'"], ["\\", "'"], substr($raw, 1, -1))
            : stripcslashes(substr($raw, 1, -1));

        foreach ($ignorePrefixes as $prefix) {
            if (str_starts_with($value, $prefix)) {
                continue 2;
            }
        }

        $keys[$value] = true;
    }
}

ksort($keys, SORT_STRING | SORT_FLAG_CASE);

$languages = [];
foreach (glob($root . '/languages/*.json') ?: [] as $file) {
    $code = strtolower((string) pathinfo($file, PATHINFO_FILENAME));
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        fwrite(STDERR, "Invalid JSON: {$file}\n");
        exit(1);
    }
    $languages[$code] = $data;
}

$report = [];
$hasMissing = false;
foreach ($languages as $code => $dictionary) {
    $missing = [];
    foreach (array_keys($keys) as $key) {
        if (!array_key_exists($key, $dictionary) || $dictionary[$key] === '') {
            $missing[] = $key;
        }
    }
    if ($missing !== []) {
        $hasMissing = true;
    }
    $report[$code] = $missing;
}

if ($jsonOutput) {
    echo json_encode([
        'total_keys' => count($keys),
        'missing' => $report,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit($hasMissing ? 1 : 0);
}

echo 'Keys used in code: ' . count($keys) . "\n";
foreach ($report as $code => $missing) {
    echo "\n{$code}: " . count($missing) . " missing\n";
    foreach ($missing as $key) {
        echo '  - ' . $key . "\n";
    }
}

exit($hasMissing ? 1 : 0);
