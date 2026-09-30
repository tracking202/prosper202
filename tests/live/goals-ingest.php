<?php

declare(strict_types=1);

/**
 * The live pass's way into the goals engine (tests/live/goals.sh).
 *
 * PR 4 builds the engine; the paths that feed it events over HTTP are PR 4b
 * (pixels and postbacks with event=, POST /events, p202.js) and PR 5 (the
 * Android intake). Until they land, this driver calls the same entry point
 * they will — GoalEngine::ingest() — against the instance this checkout's
 * 202-config.php names, exactly as the retention cron reaches its database.
 * It is a test tool, not a surface: it lives under tests/ and nothing serves
 * it.
 *
 * stdin: {"user_id": 1, "click_id": 930001, "events": [<GoalEvent JSON>…]}
 *        received_at defaults to now for each event that omits it.
 * stdout: the ingest result as JSON, or {"error": {"reason", "message"}}
 * exit: 0 on success, 3 on a refusal the engine names, 1 otherwise.
 */

use Prosper202\Database\Connection;
use Prosper202\Goals\GoalEngine;
use Prosper202\Goals\GoalEngineException;
use Prosper202\Goals\GoalEvent;
use Prosper202\Goals\InvalidGoalDefinition;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

error_reporting(E_ALL);
require_once __DIR__ . '/../../202-config/connect.php';

$fail = static function (int $code, string $reason, string $message): never {
    echo json_encode(['error' => ['reason' => $reason, 'message' => $message]], JSON_UNESCAPED_SLASHES), "\n";
    exit($code);
};

if (!isset($db) || !($db instanceof mysqli)) {
    $fail(1, 'no_database', 'connect.php did not provide a database connection');
}
$raw = stream_get_contents(STDIN);
$input = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($input) || !is_int($input['user_id'] ?? null) || !is_int($input['click_id'] ?? null) || !is_array($input['events'] ?? null)) {
    $fail(1, 'bad_input', 'stdin must be {"user_id": int, "click_id": int, "events": [...]}');
}

$now = time();
$events = [];
try {
    foreach ($input['events'] as $i => $e) {
        if (is_array($e) && !array_key_exists('received_at', $e)) {
            $e['received_at'] = $now;
        }
        $events[] = GoalEvent::fromArray($e, 'events[' . $i . ']');
    }
} catch (InvalidGoalDefinition $e) {
    $fail(3, 'invalid_event', $e->getMessage());
}

try {
    $engine = new GoalEngine(new Connection($db));
    $result = $engine->ingest($input['user_id'], $engine->clickSubject($input['user_id'], $input['click_id']), $events);
} catch (GoalEngineException $e) {
    $fail(3, $e->reason, $e->getMessage());
}

echo json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
