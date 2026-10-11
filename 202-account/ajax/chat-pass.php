<?php

declare(strict_types=1);

include_once(str_repeat('../', 2) . '202-config/connect.php');
include_once(CONFIG_PATH . '/Messaging/ChatPassClient.class.php');

/**
 * The chat box's pass. POST only, signed in, with
 * the session token; the identity comes from the session and the database,
 * never from the request.
 *
 *   200 {ok: true, pass, chat_url, expires_at_s, user: {name}}
 *   401 not signed in, 403 bad token or no active user, 405 not a POST
 *   400 refresh is neither 1 nor 0
 *   409 {ok: false, error: "no_api_key"} — the install has no customer API
 *       key (nor the install hash it goes with): the chat box stays hidden
 *   500 {ok: false, error: "internal"} — the user could not be read
 *   502 {ok: false, error: "chat_unavailable"} — the portal gave no pass
 *
 * The install hash and the API key go to the portal (CHAT_PASS_URL) and are
 * never in the answer. The pass is kept in the session until an hour before
 * it expires, keyed by a fingerprint of everything it was asked for with, so
 * a changed key, email or name asks again; refresh=1 (sent once by the chat
 * box when the chat service refused the pass) asks again regardless.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$p202ChatAnswer = static function (int $status, array $body): never {
    http_response_code($status);
    $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    echo $json === false ? '{"ok":false,"error":"internal"}' : $json;
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $p202ChatAnswer(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

// Signed in (remember-me included), without the license gate: chat is how a
// user whose license check fails reaches support. JSON 401 otherwise.
require __DIR__ . '/messaging/_auth.php';

if (!AUTH::check_csrf_token()) {
    $p202ChatAnswer(403, ['ok' => false, 'error' => 'invalid token']);
}

$p202ChatRefresh = $_POST['refresh'] ?? '0';
if ($p202ChatRefresh !== '0' && $p202ChatRefresh !== '1') {
    $p202ChatAnswer(400, ['ok' => false, 'error' => 'refresh must be 1 or 0']);
}

try {
    $p202ChatIdentity = MessagingService::identityFor(DB::getInstance()->getConnection(), $messagingUserId);
} catch (\Throwable $e) {
    error_log('Prosper202 chat: the identity of user ' . $messagingUserId . ' could not be read: ' . $e->getMessage());
    $p202ChatAnswer(500, ['ok' => false, 'error' => 'internal']);
}
if ($p202ChatIdentity === null) {
    $p202ChatAnswer(403, ['ok' => false, 'error' => 'forbidden']);
}
if ($p202ChatIdentity['api_key'] === '' || $p202ChatIdentity['install_hash'] === '') {
    $p202ChatAnswer(409, ['ok' => false, 'error' => 'no_api_key']);
}

$p202ChatRequest = [
    'api_key'      => $p202ChatIdentity['api_key'],
    'install_hash' => $p202ChatIdentity['install_hash'],
    'user_id'      => $p202ChatIdentity['user_id'],
    'user_email'   => $p202ChatIdentity['user_email'],
    'user_name'    => $p202ChatIdentity['user_name'],
];

// The install's address as the portal shows it to the team (and puts in the
// replies it emails): sent to someone else, so it is the stored tracking
// domain or the server's own name, never the request's Host (CLAUDE.md #16).
// Unknown is '' in the protocol, which is what a failed read sends.
try {
    $p202ChatInstallUrl = p202TrackingBaseUrl();
} catch (\Throwable $e) {
    error_log('Prosper202 chat: the install URL could not be read: ' . $e->getMessage());
    $p202ChatInstallUrl = '';
}
if (strlen($p202ChatInstallUrl) > 300 || preg_match('#^https?://#i', $p202ChatInstallUrl) !== 1) {
    $p202ChatInstallUrl = '';
}

try {
    $p202ChatFingerprint = hash('sha256', json_encode(
        [$p202ChatRequest, $p202ChatInstallUrl, CHAT_PASS_URL],
        JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
    ));
} catch (\JsonException $e) {
    error_log('Prosper202 chat: the pass request could not be encoded: ' . $e->getMessage());
    $p202ChatAnswer(500, ['ok' => false, 'error' => 'internal']);
}

$p202ChatCached = $_SESSION['p202_chat_pass'] ?? null;
$p202ChatPass = null;
if (
    $p202ChatRefresh === '0'
    && is_array($p202ChatCached)
    && ($p202ChatCached['for'] ?? null) === $p202ChatFingerprint
    && is_string($p202ChatCached['pass'] ?? null)
    && is_string($p202ChatCached['chat_url'] ?? null)
    && is_int($p202ChatCached['expires_at_s'] ?? null)
    && $p202ChatCached['expires_at_s'] - 3600 > time()
) {
    $p202ChatPass = [
        'pass'         => $p202ChatCached['pass'],
        'chat_url'     => $p202ChatCached['chat_url'],
        'expires_at_s' => $p202ChatCached['expires_at_s'],
    ];
}

if ($p202ChatPass === null) {
    try {
        $p202ChatPass = (new ChatPassClient())->request($p202ChatRequest, $p202ChatInstallUrl);
    } catch (\Throwable $e) {
        error_log('Prosper202 chat: no pass for user ' . $messagingUserId . ': ' . $e->getMessage());
        if ($p202ChatCached !== null) {
            withWritableSession(static function (): void {
                unset($_SESSION['p202_chat_pass']);
            });
        }
        $p202ChatAnswer(502, ['ok' => false, 'error' => 'chat_unavailable']);
    }
    $p202ChatFresh = $p202ChatPass;
    withWritableSession(static function () use ($p202ChatFingerprint, $p202ChatFresh): void {
        $_SESSION['p202_chat_pass'] = ['for' => $p202ChatFingerprint] + $p202ChatFresh;
    });
}

$p202ChatAnswer(200, [
    'ok'           => true,
    'pass'         => $p202ChatPass['pass'],
    'chat_url'     => $p202ChatPass['chat_url'],
    'expires_at_s' => $p202ChatPass['expires_at_s'],
    'user'         => ['name' => $p202ChatIdentity['user_name']],
]);
