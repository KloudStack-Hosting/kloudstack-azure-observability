<?php

/**
 * Stamp a resolved version into the plugin bootstrap.
 *
 * Usage: php scripts/stamp-version.php <version> <path-to-bootstrap>
 *
 * The release workflow calls this against the STAGED copy, never the working tree, so the build
 * stays reproducible and the repository is untouched.
 *
 * This exists as a file rather than an inline `php -r` in the workflow because the patterns
 * contain $, quotes and backreferences, and inlining them meant escaping through YAML, then the
 * shell, then PHP. That was fragile enough to break silently — and a version stamp that fails
 * silently is worse than no stamp, since the package then lies about what it contains.
 *
 * @package KloudStack\Observability
 */

declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "usage: stamp-version.php <version> <file>\n");
    exit(2);
}

[$version, $file] = [$argv[1], $argv[2]];

if (!is_file($file) || !is_readable($file)) {
    fwrite(STDERR, "not readable: {$file}\n");
    exit(2);
}

$source = file_get_contents($file);

if ($source === false) {
    fwrite(STDERR, "could not read: {$file}\n");
    exit(2);
}

$replacements = 0;

// Plugin header — what WordPress shows on the Plugins screen.
$source = preg_replace(
    '/^(\s*\*\s*Version:\s*).+$/m',
    '${1}' . $version,
    $source,
    1,
    $headerCount
);
$replacements += (int) $headerCount;

// VERSION constant — what the diagnostics report and Azure's sdkVersion carry.
$source = preg_replace(
    "/^(const VERSION\s*=\s*')[^']+/m",
    '${1}' . $version,
    $source,
    1,
    $constCount
);
$replacements += (int) $constCount;

if ($replacements !== 2) {
    fwrite(STDERR, "expected 2 replacements, made {$replacements}. Bootstrap format changed?\n");
    exit(1);
}

if (file_put_contents($file, $source) === false) {
    fwrite(STDERR, "could not write: {$file}\n");
    exit(2);
}

echo "stamped {$version}\n";
