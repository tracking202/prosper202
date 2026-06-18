<?php

declare(strict_types=1);

/**
 * Mock central messaging server.
 *
 * A self-contained, dependency-free stand-in for the my.tracking202.com messaging
 * API so you can click through the Prosper202 messenger widget locally. It
 * implements the contract in 202-config/Messaging/CENTRAL-API.md:
 *   POST /messaging/pull
 *   POST /messaging/send
 *   POST /messaging/read
 *   POST /messaging/track
 * plus two dev conveniences:
 *   GET  /            -> human-readable status page
 *   GET  /reset       -> wipe all mock state
 *
 * Run it with PHP's built-in server (it acts as the router):
 *
 *   php -S 127.0.0.1:8787 202-config/Messaging/mock-server.php
 *
 * Then start Prosper202 with the messaging URL pointed at the mock:
 *
 *   MESSAGING_API_URL=http://127.0.0.1:8787/messaging \
 *   MESSAGING_SYNC_THROTTLE=0 \
 *   php -S 127.0.0.1:8080 -t /path/to/prosper202
 *
 * State is persisted to a JSON file in the system temp dir so conversations
 * survive across requests. This is a DEV TOOL — no auth, no hardening.
 */

// ---------------------------------------------------------------------------
// State persistence
// ---------------------------------------------------------------------------

function state_path(): string
{
    return sys_get_temp_dir() . '/p202_mock_messaging.json';
}

/** @return array<string,mixed> */
function load_state(): array
{
    $path = state_path();
    if (!is_file($path)) {
        return ['users' => [], 'seq' => 0];
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return ['users' => [], 'seq' => 0];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['users' => [], 'seq' => 0];
}

/** @param array<string,mixed> $state */
function save_state(array $state): void
{
    file_put_contents(state_path(), json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
}

function next_id(array &$state, string $prefix): string
{
    $state['seq'] = (int) ($state['seq'] ?? 0) + 1;
    return $prefix . '_' . $state['seq'];
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

// ---------------------------------------------------------------------------
// Request helpers
// ---------------------------------------------------------------------------

/** @return array<string,mixed> */
function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function send_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

/** Identify the user across requests; prefer user_id, fall back to email. */
function user_key(array $identity): string
{
    if (!empty($identity['user_id'])) {
        return 'uid:' . $identity['user_id'];
    }
    if (!empty($identity['user_email'])) {
        return 'email:' . $identity['user_email'];
    }
    return 'anonymous';
}

/** Ensure the user exists and has the seeded welcome content. */
function ensure_seeded(array &$state, string $key): void
{
    if (isset($state['users'][$key])) {
        return;
    }

    $welcomeConv = next_id($state, 'conv');
    $state['users'][$key] = [
        'attributes'    => (object) [],
        'events'        => [],
        'segments_sent' => [],
        'conversations' => [
            $welcomeConv => [
                'external_id'     => $welcomeConv,
                'type'            => 'broadcast',
                'subject'         => 'Welcome to Prosper202',
                'status'          => 'open',
                'last_message_at' => now(),
                'messages'        => [
                    [
                        'external_id' => next_id($state, 'msg'),
                        'direction'   => 'inbound',
                        'author'      => 'team',
                        'body'        => "👋 Welcome to Prosper202! This is the in-app messenger. Reply here any time and our team will get back to you.",
                        'created_at'  => now(),
                    ],
                ],
            ],
        ],
    ];
}

/**
 * Segmentation demo: if the user's custom attributes mark them as a "pro" plan,
 * push a one-time targeted broadcast. This shows attributes set via
 * Prosper202Messenger('update', {plan:'pro'}) driving who receives a message.
 */
function apply_segmentation(array &$state, string $key): void
{
    $user = &$state['users'][$key];
    $plan = is_object($user['attributes'] ?? null)
        ? ($user['attributes']->plan ?? null)
        : ($user['attributes']['plan'] ?? null);

    if ($plan === 'pro' && !in_array('pro_welcome', $user['segments_sent'], true)) {
        $conv = next_id($state, 'conv');
        $user['conversations'][$conv] = [
            'external_id'     => $conv,
            'type'            => 'broadcast',
            'subject'         => 'A Pro tip, just for you',
            'status'          => 'open',
            'last_message_at' => now(),
            'messages'        => [[
                'external_id' => next_id($state, 'msg'),
                'direction'   => 'inbound',
                'author'      => 'team',
                'body'        => "Because you're on the Pro plan, here's an advanced attribution walkthrough we think you'll like.",
                'created_at'  => now(),
            ]],
        ];
        $user['segments_sent'][] = 'pro_welcome';
    }
}

/** Flatten a user's conversations (associative -> list) for the API response. */
function conversations_list(array $user): array
{
    $out = [];
    foreach ($user['conversations'] as $conv) {
        $out[] = [
            'external_id'     => $conv['external_id'],
            'type'            => $conv['type'],
            'subject'         => $conv['subject'],
            'status'          => $conv['status'],
            'last_message_at' => $conv['last_message_at'],
            'messages'        => array_values($conv['messages']),
        ];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
// Tolerate both "/messaging/pull" and "/pull".
$route  = preg_replace('#^/messaging#', '', $path);
$route  = $route === '' ? '/' : $route;

// --- dev conveniences ------------------------------------------------------
if ($method === 'GET' && $route === '/reset') {
    @unlink(state_path());
    send_json(['ok' => true, 'reset' => true]);
}

if ($method === 'GET' && $route === '/') {
    $state = load_state();
    header('Content-Type: text/html');
    echo "<h1>Prosper202 mock messaging server</h1>";
    echo "<p>Running. State file: <code>" . htmlspecialchars(state_path()) . "</code></p>";
    echo "<p>Users in state: " . count($state['users'] ?? []) . " — <a href='/reset'>reset</a></p>";
    echo "<pre>" . htmlspecialchars(json_encode($state, JSON_PRETTY_PRINT)) . "</pre>";
    exit;
}

// --- API endpoints (all POST + JSON) ---------------------------------------
if ($method !== 'POST') {
    send_json(['ok' => false, 'error' => 'method not allowed'], 405);
}

$body     = read_json_body();
$identity = is_array($body['identity'] ?? null) ? $body['identity'] : [];
$key      = user_key($identity);

$state = load_state();

switch ($route) {
    case '/pull':
        ensure_seeded($state, $key);
        // Fold the latest attributes into segmentation, then return everything.
        if (!empty($identity['attributes']) && is_array($identity['attributes'])) {
            $state['users'][$key]['attributes'] = (object) $identity['attributes'];
        }
        apply_segmentation($state, $key);
        save_state($state);

        send_json([
            'ok'            => true,
            'server_time'   => now(),
            'cursor'        => (string) time(),
            'conversations' => conversations_list($state['users'][$key]),
        ]);
        // no break — send_json exits

    case '/send':
        ensure_seeded($state, $key);
        $user = &$state['users'][$key];

        $convExtId = $body['conversation_external_id'] ?? null;
        $text      = trim((string) ($body['body'] ?? ''));
        $clientTok = (string) ($body['client_token'] ?? next_id($state, 'tok'));

        if ($text === '') {
            send_json(['ok' => false, 'error' => 'empty body'], 400);
        }

        // New conversation if none supplied or unknown.
        if (!$convExtId || !isset($user['conversations'][$convExtId])) {
            $convExtId = next_id($state, 'conv');
            $user['conversations'][$convExtId] = [
                'external_id'     => $convExtId,
                'type'            => 'conversation',
                'subject'         => 'Conversation',
                'status'          => 'open',
                'last_message_at' => now(),
                'messages'        => [],
            ];
        }

        $messageExtId = next_id($state, 'msg');
        $userMessage  = [
            'external_id'  => $messageExtId,
            'client_token' => $clientTok,
            'direction'    => 'outbound',
            'author'       => 'user',
            'body'         => $text,
            'created_at'   => now(),
        ];
        $user['conversations'][$convExtId]['messages'][] = $userMessage;

        // Canned team auto-reply so two-way conversations are visible on next pull.
        $user['conversations'][$convExtId]['messages'][] = [
            'external_id' => next_id($state, 'msg'),
            'direction'   => 'inbound',
            'author'      => 'team',
            'body'        => "Thanks for your message! A teammate will follow up shortly. (This is an automated mock reply.)",
            'created_at'  => now(),
        ];
        $user['conversations'][$convExtId]['last_message_at'] = now();
        save_state($state);

        send_json([
            'ok'           => true,
            'conversation' => [
                'external_id' => $convExtId,
                'type'        => $user['conversations'][$convExtId]['type'],
                'subject'     => $user['conversations'][$convExtId]['subject'],
                'status'      => 'open',
            ],
            'message'      => [
                'external_id'  => $messageExtId,
                'client_token' => $clientTok,
                'direction'    => 'outbound',
                'author'       => 'user',
                'body'         => $text,
                'created_at'   => $userMessage['created_at'],
            ],
        ]);
        // no break

    case '/read':
        // Nothing to track in the mock; just acknowledge.
        send_json(['ok' => true]);
        // no break

    case '/track':
        ensure_seeded($state, $key);
        $user = &$state['users'][$key];

        if (isset($body['attributes']) && is_array($body['attributes'])) {
            $merged = (array) $user['attributes'];
            foreach ($body['attributes'] as $k => $v) {
                $merged[$k] = $v;
            }
            $user['attributes'] = (object) $merged;
        }

        if (isset($body['events']) && is_array($body['events'])) {
            foreach ($body['events'] as $event) {
                if (is_array($event)) {
                    $user['events'][] = $event;
                }
            }
        }

        // Attributes may newly qualify the user for a segmented broadcast.
        apply_segmentation($state, $key);
        save_state($state);

        send_json(['ok' => true]);
        // no break

    default:
        send_json(['ok' => false, 'error' => 'not found: ' . $route], 404);
}
