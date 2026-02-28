#!/usr/bin/env php
<?php
/**
 * Docker seed script: creates the database schema, admin user, and API key.
 *
 * Usage:  php docker-seed.php
 *
 * Expects 202-config.php to already point at a reachable MySQL instance.
 */

declare(strict_types=1);

// Minimal web-server shim so connect.php doesn't crash.
$_SERVER['SERVER_NAME'] = $_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);
$_SERVER['REQUEST_URI'] = '/build/scripts/docker-seed.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['PHP_SELF'] = '/build/scripts/docker-seed.php';

// connect.php calls session_start()
if (session_status() === PHP_SESSION_NONE) {
    // Avoid session cookie warnings in CLI
    ini_set('session.use_cookies', '0');
    ini_set('session.use_only_cookies', '0');
}

require_once dirname(__DIR__, 2) . '/202-config/connect.php';
require_once dirname(__DIR__, 2) . '/202-config/functions-install.php';

// ── 1. Install schema ──────────────────────────────────────────────────
echo "Installing database schema...\n";
$installer = new INSTALL();
$installer->install_databases();
echo "Schema installed.\n";

// ── 2. Create admin user ───────────────────────────────────────────────
echo "Creating admin user...\n";
$username = 'admin';
$email    = 'admin@example.com';
$password = 'password123';

$hasher = function_exists('hash_user_pass') ? 'hash_user_pass' : 'salt_user_pass';
$hashed = $hasher($password);
$hash   = md5(uniqid((string)random_int(0, mt_getrandmax()), true));

$stmt = $db->prepare("INSERT IGNORE INTO 202_users SET
    user_email = ?, user_name = ?, user_pass = ?,
    user_timezone = 'UTC', user_time_register = ?,
    install_hash = ?, user_hash = '', p202_customer_api_key = ''");
$now = (string)time();
$stmt->bind_param('sssss', $email, $username, $hashed, $now, $hash);
if (!$stmt->execute()) {
    fwrite(STDERR, "Failed to insert admin user: {$stmt->error}\n");
    $stmt->close();
    exit(1);
}
$userId = $stmt->insert_id ?: 0;
$stmt->close();

if ($userId === 0) {
    $res = $db->query("SELECT user_id FROM 202_users WHERE user_name = 'admin' LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    $userId = $row ? (int)$row['user_id'] : 0;
}

if ($userId === 0) {
    fwrite(STDERR, "Could not determine admin user_id\n");
    exit(1);
}

// User preferences
$db->query("INSERT IGNORE INTO 202_users_pref SET user_id = {$userId}");

// Admin role
$db->query("INSERT IGNORE INTO 202_user_role (user_id, role_id) VALUES ({$userId}, 1)");

echo "Admin user created (id={$userId}, username=admin, password=password123).\n";

// ── 3. Create API key ──────────────────────────────────────────────────
echo "Creating API key...\n";

// Generate a deterministic-looking but unique key for easy testing
$apiKey = 'p202_test_' . bin2hex(random_bytes(16));

$apiKeyHash = hash('sha256', $apiKey);
$label = 'docker-test-key';
$nowTs = time();
$createdAt = date('Y-m-d H:i:s', $nowTs);

// Check if api_keys table exists
$tableCheck = $db->query("SHOW TABLES LIKE '202_api_keys'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $stmt = $db->prepare("INSERT INTO 202_api_keys (user_id, api_key_hash, label, created_at, scopes) VALUES (?, ?, ?, ?, ?)");
    $scopes = 'admin,sync:read,sync:write';
    $stmt->bind_param('issss', $userId, $apiKeyHash, $label, $createdAt, $scopes);
    if (!$stmt->execute()) {
        fwrite(STDERR, "Failed to insert API key: {$stmt->error}\n");
        $stmt->close();
        exit(1);
    }
    $stmt->close();
    echo "API key created: {$apiKey}\n";
} else {
    echo "WARNING: 202_api_keys table not found. API key not created.\n";
    echo "The API may use a different auth mechanism.\n";
}

// ── 4. Try partitions (non-fatal) ──────────────────────────────────────
echo "Installing partitions (optional)...\n";
try {
    $installer->install_database_partitions();
    echo "Partitions installed.\n";
} catch (Throwable $e) {
    echo "Partitions skipped: {$e->getMessage()}\n";
}

echo "\n=== Docker seed complete ===\n";
echo "URL:      http://localhost:8080\n";
echo "API:      http://localhost:8080/api/v3/\n";
echo "User:     admin / password123\n";
echo "API Key:  {$apiKey}\n";
