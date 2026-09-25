<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-17) . '/202-config/connect.php');

AUTH::require_user();

/*
 * A traffic source's custom variables: saved and cleared from the variables
 * dialog on Setup › Traffic Sources.
 *
 * Both actions write, so both require the session token, as every other
 * Setup write does (error pattern #5): the classic page never sent one and
 * nothing here asked for it. The v2 page posts through jQuery, whose
 * prefilter in template.php attaches the token to every same-origin POST.
 *
 * The source must be the signed-in user's, and a variable id is only ever
 * updated or kept within that source: before U4 an id from another source
 * (or a string spliced into the NOT IN list) reached the SQL as posted.
 *
 * The markup fragments this file used to return (add_more_variables,
 * get_vars) went with the classic page; the v2 page renders the variables
 * itself.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('POST only');
}

if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? ''))) {
    http_response_code(403);
    die('Invalid token, please reload the page and try again.');
}

$ppcNetworkId = (int) ($_POST['ppc_network_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$owner = $db->query("SELECT ppc_network_id FROM 202_ppc_networks WHERE ppc_network_id = '" . $ppcNetworkId . "' AND user_id = '" . $userId . "' AND ppc_network_deleted = 0");
if (!$owner instanceof mysqli_result) {
    record_mysql_error($db, 'SELECT ppc_network_id FROM 202_ppc_networks (custom variables owner check)');
}
if ($owner->num_rows === 0) {
    http_response_code(404);
    die('That traffic source is not yours, or it was removed.');
}

if (isset($_POST['post_vars']) && $_POST['post_vars'] == true && isset($_POST['vars']) && is_array($_POST['vars'])) {
    $kept = [];

    foreach ($_POST['vars'] as $var) {
        if (!is_array($var)) {
            http_response_code(400);
            die('VALIDATION FAILD!');
        }
        $var_empty = count($var) != count(array_filter($var, static fn ($value): bool => trim((string) $value) !== ''));
        if ($var_empty) {
            http_response_code(400);
            die('VALIDATION FAILD!');
        }

        $mysql['name'] = $db->real_escape_string((string) ($var['name'] ?? ''));
        $mysql['parameter'] = $db->real_escape_string((string) ($var['parameter'] ?? ''));
        $mysql['placeholder'] = $db->real_escape_string((string) ($var['placeholder'] ?? ''));
        $isNew = (string) ($var['id'] ?? 'false') === 'false';
        $variableId = $isNew ? 0 : (int) $var['id'];

        $set = "SET ppc_network_id = '" . $ppcNetworkId . "', name = '" . $mysql['name'] . "', parameter = '" . $mysql['parameter'] . "', placeholder = '" . $mysql['placeholder'] . "'";
        $sql = $isNew
            ? "INSERT INTO 202_ppc_network_variables " . $set
            : "UPDATE 202_ppc_network_variables " . $set . " WHERE ppc_variable_id = '" . $variableId . "' AND ppc_network_id = '" . $ppcNetworkId . "'";
        $db->query($sql) or record_mysql_error($db, $sql);

        $kept[] = $isNew ? (int) $db->insert_id : $variableId;
    }

    // Everything this source had that the dialog no longer lists is retired.
    $keptList = $kept === [] ? '0' : implode(', ', $kept);
    $sql = "UPDATE 202_ppc_network_variables SET deleted = '1' WHERE ppc_variable_id NOT IN (" . $keptList . ") AND ppc_network_id = '" . $ppcNetworkId . "'";
    $db->query($sql) or record_mysql_error($db, $sql);

    echo 'DONE!';
    exit;
}

if (isset($_POST['delete_vars']) && $_POST['delete_vars'] == true) {
    $sql = "DELETE FROM 202_ppc_network_variables WHERE ppc_network_id = '" . $ppcNetworkId . "' AND deleted = '0'";
    $db->query($sql) or record_mysql_error($db, $sql);
    echo 'DONE!';
    exit;
}

http_response_code(400);
echo 'Unknown action.';
