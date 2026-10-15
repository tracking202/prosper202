<?php
declare(strict_types=1);
header('Content-Type: application/json');
include_once(substr(__DIR__, 0,-19) . '/202-config/connect2.php');
$data = [];
$trackerId = \Prosper202\Click\PublicId::parse($_GET['t202id'] ?? null);
// The tracker's traffic source, only when the source is the tracker's account's.
try {
    $row = $trackerId === null ? null : \Prosper202\Repository\LookupRepositoryFactory::reads($db)->tracker($trackerId);
} catch (\Throwable $readError) {
    // A failed read is not "no variables".
    error_log('get_custom_vars.php: could not read tracker ' . $trackerId . ': ' . $readError->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The tracker could not be read']);
    exit;
}
if ($row !== null) {
	// The source's variable names: none for a source that has none (its NULL
	// list answered [""]), as TrackerVariables reads them for the click.
	$data = array_column(\Prosper202\Click\TrackerVariables::pairs($row), 0);
}

echo json_encode($data, JSON_UNESCAPED_UNICODE);