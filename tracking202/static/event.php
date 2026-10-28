<?php

declare(strict_types=1);

/**
 * p202.track(name, props) — the landing page script's event intake (plan
 * §2.2). A landing or thank-you page that loads landing.php reports what the
 * visitor did; the event is stored on the visitor's click and evaluated by
 * that campaign's goals, exactly as an `event=` postback or POST /events is.
 *
 * Which click: the one the page's first-party visitor id (p202lpid, §6.2)
 * was last seen on — never a click id from the page, which anyone can count
 * to (CLAUDE.md #16). The id is 128 random bits in the page's own storage,
 * so a caller can only report events for clicks its own browser made. A
 * visitor who refused consent, or a campaign with identity capture off,
 * has no id, and the script sends nothing.
 *
 * Trust: a page script is the least trusted caller there is, so `revenue`
 * is stored and reported but never paid (revenue_trusted false); a goal
 * valued from it is tracked with value_note untrusted_value. Goals with a
 * fixed value pay as they would for any other path.
 *
 * Request: POST, application/x-www-form-urlencoded (a CORS-safelisted type,
 * so a page on another site sends it without a preflight, and
 * navigator.sendBeacon can deliver it while the page unloads):
 *   lpip       the landing page's public id (from the snippet)
 *   p202lpid   the page's visitor id
 *   event_id   the script's id for this event (a retry resends it)
 *   name       the event name
 *   props      optional JSON object of properties
 *   revenue    optional decimal amount
 * Answer: 202 with {"accepted": bool, "duplicate": bool, "event_id"} when
 * stored; 204 when no click can be tied to the visitor (nothing is stored);
 * 422 naming the bad field; 409 for an event id reused with other content.
 * The body never says which click was found, only that one was.
 */

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
// The page is on the operator's site, the tracker on another: any origin may
// post (no credentials are involved, and the visitor id is the proof).
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$p202EventRoot = dirname(__DIR__, 2);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (!file_exists($p202EventRoot . '/202-config.php') || !file_exists($p202EventRoot . '/vendor/autoload.php')) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":true,"message":"Service unavailable","status":503}';
    exit;
}
require_once $p202EventRoot . '/vendor/autoload.php';

const P202_EVENT_MAX_BODY = 16384;
\Api\V3\Apps\PublicIntake::preflight(P202_EVENT_MAX_BODY, ['POST']);
// At file scope: the DB constructor reads its settings through `global`
// (see .well-known/postback-prelude.php), and the rate limiter's store is
// scoped by the database those settings name, so it comes after them.
require_once $p202EventRoot . '/202-config.php';
\Api\V3\Apps\PublicIntake::rateLimit('web-event', 600, 60);

$p202EventBody = \Api\V3\Apps\PublicIntake::readBody(P202_EVENT_MAX_BODY);
if (strlen($p202EventBody) > P202_EVENT_MAX_BODY) {
    \Api\V3\Bootstrap::errorResponse('Request body too large', 413);
    exit;
}
parse_str($p202EventBody, $p202EventForm);

/** @param array<string, string> $errors */
$p202EventRefuse = static function (int $status, string $message, array $errors = []): never {
    \Api\V3\Bootstrap::errorResponse($message, $status, $errors === [] ? [] : ['field_errors' => $errors]);
    exit;
};

$p202Field = static function (string $key) use ($p202EventForm): ?string {
    $v = $p202EventForm[$key] ?? null;

    return is_string($v) ? $v : ($v === null ? null : '');
};
$p202EventErrors = [];
foreach (array_keys($p202EventForm) as $p202Key) {
    if (!in_array((string) $p202Key, ['lpip', 'p202lpid', 'event_id', 'name', 'props', 'revenue'], true)) {
        $p202EventErrors[(string) $p202Key] = 'is not a field here (accepted: lpip, p202lpid, event_id, name, props, revenue)';
    }
}
$p202Props = [];
if (($p202Field('props') ?? '') !== '') {
    $p202Decoded = json_decode((string) $p202Field('props'), true, 4);
    if (!is_array($p202Decoded) || ($p202Decoded !== [] && array_is_list($p202Decoded))) {
        $p202EventErrors['props'] = 'must be a JSON object of properties';
    } else {
        $p202Props = $p202Decoded;
    }
}
$p202Revenue = null;
if (($p202Field('revenue') ?? '') !== '') {
    $p202Rev = (string) $p202Field('revenue');
    if (preg_match('/^-?(?:0|[1-9]\d{0,11})(?:\.\d{1,5})?$/D', $p202Rev) !== 1) {
        $p202EventErrors['revenue'] = 'must be a decimal number with at most 5 decimal places';
    } else {
        $p202Revenue = str_contains($p202Rev, '.') ? (float) $p202Rev : (int) $p202Rev;
    }
}
$p202Now = time();
try {
    $p202Event = \Prosper202\Goals\GoalEvent::fromArray(array_filter([
        'event_id' => $p202Field('event_id'),
        'name' => $p202Field('name'),
        'occurred_at' => $p202Now,
        'received_at' => $p202Now,
        'properties' => $p202Props === [] ? null : $p202Props,
        'revenue' => $p202Revenue,
        'revenue_trusted' => false,
    ], static fn ($v): bool => $v !== null), 'event')->clockedByServer();
} catch (\Prosper202\Goals\InvalidGoalDefinition $p202Invalid) {
    foreach ($p202Invalid->errors() as $p202Path => $p202Message) {
        $p202EventErrors[str_starts_with($p202Path, 'event.properties') ? 'props' . substr($p202Path, strlen('event.properties')) : substr($p202Path, strlen('event.'))] ??= $p202Message;
    }
}
if ($p202EventErrors !== []) {
    ksort($p202EventErrors);
    $p202EventRefuse(422, 'The event is invalid', $p202EventErrors);
}

try {
    $p202Db = \Api\V3\Apps\PublicIntake::database();
    $p202Conn = new \Prosper202\Database\Connection($p202Db);
    $p202Click = (new \Prosper202\Goals\WebEvents($p202Conn))->clickForVisitor((string) ($p202Field('lpip') ?? ''), (string) ($p202Field('p202lpid') ?? ''), $p202Now);
    if ($p202Click === null) {
        // Nothing ties this visitor to a click: no consent, capture off, an
        // id never seen on a click, or a page that is not this install's.
        http_response_code(204);
        exit;
    }
    $p202Engine = new \Prosper202\Goals\GoalEngine($p202Conn, null, null, null, new \Prosper202\Goals\TrafficSourceNotifier($p202Conn, false));
    $p202Result = $p202Engine->ingest($p202Click['user_id'], $p202Engine->clickSubject($p202Click['user_id'], $p202Click['click_id']), [$p202Event]);
} catch (\Prosper202\Goals\GoalEngineException $p202Refused) {
    match ($p202Refused->reason) {
        \Prosper202\Goals\GoalEngineException::EVENT_CONFLICT => $p202EventRefuse(409, $p202Refused->getMessage()),
        \Prosper202\Goals\GoalEngineException::EVENT_CAP => $p202EventRefuse(422, $p202Refused->getMessage()),
        default => null,
    };
    error_log('p202 track: ' . $p202Refused->getMessage());
    $p202EventRefuse(500, 'Internal server error');
} catch (\Throwable $p202Failure) {
    error_log('p202 track: ' . $p202Failure->getMessage());
    $p202EventRefuse(500, 'Internal server error');
}

\Api\V3\Bootstrap::jsonResponse([
    'accepted' => $p202Result['accepted'] !== [],
    'duplicate' => $p202Result['accepted'] === [],
    'event_id' => $p202Event->eventId,
], 202);
