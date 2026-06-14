<?php
declare(strict_types=1);
/**
 * Generate 202-config.php from 202-config-sample.php using values supplied by
 * the environment. The Docker entrypoint calls this so the container wires its
 * own database connection — the wizard then opens with the DB step already done,
 * no file editing required.
 *
 * This mirrors the line rewrite in 202-config/setup-config.php: it reuses the
 * exact same placeholder tokens that live in 202-config-sample.php, and the same
 * single-quote escaping, so the two stay in sync. It is a no-op (exit 0) if
 * 202-config.php already exists, making it safe to run on every container start.
 */

$root   = dirname(__DIR__, 2) . '/';   // build/scripts -> repo root
$sample = $root . '202-config-sample.php';
$target = $root . '202-config.php';

if (file_exists($target)) {
    fwrite(STDERR, "202-config.php already exists; leaving it untouched.\n");
    exit(0);
}
if (!file_exists($sample)) {
    fwrite(STDERR, "Error: 202-config-sample.php not found at {$sample}\n");
    exit(1);
}

// Escape a value for inclusion in a single-quoted PHP string (same rule as
// escape_config_value() in 202-config/setup-config.php).
$escape = static fn (string $value): string =>
    str_replace(['\\', "'"], ['\\\\', "\\'"], $value);

$dbHost = getenv('DB_HOST') ?: 'db';

// Placeholder token in 202-config-sample.php => value from the environment.
$replacements = [
    'putyourdbnamehere' => getenv('MYSQL_DATABASE') ?: 'prosper202',
    'usernamehere'      => getenv('DB_USER') ?: 'root',
    'yourpasswordhere'  => getenv('MYSQL_ROOT_PASSWORD') ?: '',
    'localhosthere'     => $dbHost,
    'localhostreplica'  => getenv('DB_HOST_RO') ?: $dbHost,
    'localhostmemcache' => getenv('MC_HOST') ?: 'memcached',
];

$content = file_get_contents($sample);
if ($content === false) {
    fwrite(STDERR, "Error: could not read {$sample}\n");
    exit(1);
}

// Single-pass replacement: strtr substitutes every token at once and never
// re-scans text it just inserted, so a credential value that happens to contain
// another placeholder token can't be double-substituted.
$map = [];
foreach ($replacements as $token => $value) {
    $map[$token] = $escape((string) $value);
}
$content = strtr($content, $map);

if (file_put_contents($target, $content) === false) {
    fwrite(STDERR, "Error: could not write {$target}\n");
    exit(1);
}
// 202-config.php holds DB credentials, so it must stay non-world-readable (0640)
// AND be readable by the web server. This script runs from the Docker entrypoint
// as root while Apache serves as www-data, so a root-owned file is unreadable by
// the web server and would 500 every page — hand ownership to the web user.
// chown succeeds when running as root, or as a no-op when we already ARE the web
// user (chowning a file to its own owner). If it fails for any other reason we
// fail loudly rather than weaken permissions on a credentials file: a silent
// 0644 would expose DB credentials and mask a misconfigured image.
chmod($target, 0640);
$webUser  = getenv('APACHE_RUN_USER') ?: 'www-data';
$webGroup = getenv('APACHE_RUN_GROUP') ?: $webUser;
if (@chown($target, $webUser)) {
    @chgrp($target, $webGroup);
} else {
    fwrite(STDERR, "Error: could not give 202-config.php to the web user '{$webUser}'. "
        . "Run this as root (the Docker entrypoint does), or chown the file to '{$webUser}' "
        . "yourself. Refusing to relax permissions on a file containing DB credentials.\n");
    exit(1);
}

echo "Wrote 202-config.php (db host={$dbHost}).\n";
