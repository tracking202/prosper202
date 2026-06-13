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

foreach ($replacements as $token => $value) {
    $content = str_replace($token, $escape((string) $value), $content);
}

if (file_put_contents($target, $content) === false) {
    fwrite(STDERR, "Error: could not write {$target}\n");
    exit(1);
}
// Owner/group read only — this file holds database credentials.
chmod($target, 0640);

echo "Wrote 202-config.php (db host={$dbHost}).\n";
