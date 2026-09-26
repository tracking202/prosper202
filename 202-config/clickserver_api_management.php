<?php
declare(strict_types=1);
// The switch endpoint on Account › ClickServers. The pages that include this
// file for its functions (clickservers.php, api-integrations.php) must not
// run it, so it answers only when it is the requested script.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
	&& realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
	include_once(__DIR__ . '/connect.php');
	include_once(__DIR__ . '/functions-auth.php');

	AUTH::require_user();

	// The session token, the page's own permission, and the caller's own
	// key and domain: the endpoint checked only the first two of these, so
	// any signed-in user without access_to_clickservers could post here,
	// and the posted api_key and clickserver_id were used as sent, so one
	// account could switch off a domain licensed to another (#165, #173).
	$storedKey = p202_clickserver_stored_key($db, (int) ($_SESSION['user_id'] ?? 0));
	$refusal = p202_clickserver_switch_refusal(
		hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? '')),
		isset($userObj) && is_object($userObj) && $userObj->hasPermission('access_to_clickservers'),
		$storedKey,
		(string) ($_POST['clickserver_id'] ?? ''),
		(string) ($_POST['method'] ?? '')
	);
	if ($refusal !== null) {
		http_response_code($refusal[0]);
		echo $refusal[1];
		return;
	}
	if (clickserver_api_domain_act_deact($storedKey, base64_encode((string) $_POST['clickserver_id']), (string) $_POST['method'])) {
		echo true;
	}
	return;
}

/**
 * Why a ClickServer switch request is refused, as [status, sentence], or
 * null when it may go ahead: the token, the permission the page itself
 * requires, a known method, a key on file, and a domain the licence service
 * lists under THIS account's key. The key used is the stored one, never a
 * posted one.
 *
 * @param callable(string): mixed|null $listDomains  the licence service's
 *   domain list for a key (clickserver_api_domain_list() when null)
 * @return array{0: int, 1: string}|null
 */
function p202_clickserver_switch_refusal(bool $tokenOk, bool $permitted, string $storedKey, string $domain, string $method, ?callable $listDomains = null): ?array
{
	if (!$tokenOk) {
		return [403, 'Invalid or expired form token. Reload the page and try again.'];
	}
	if (!$permitted) {
		return [403, 'You do not have access to ClickServers.'];
	}
	if (!in_array($method, ['activate', 'deactivate'], true)) {
		return [400, 'That is not a ClickServer action.'];
	}
	if ($storedKey === '') {
		return [409, 'There is no ClickServer API key on file for this account.'];
	}
	if ($domain === '') {
		return [400, 'No domain was named.'];
	}
	$list = ($listDomains ?? 'clickserver_api_domain_list')($storedKey);
	if (!is_array($list)) {
		return [502, 'The ClickServer service could not be reached. Try again in a minute.'];
	}
	foreach ($list as $entry) {
		if (is_array($entry) && (string) ($entry['clickserver']['domain'] ?? '') === $domain) {
			return null;
		}
	}
	return [404, 'That domain is not activated with this account\'s key.'];
}

/** The account's own ClickServer API key, or '' when it has none. A read that fails is an error (#1). */
function p202_clickserver_stored_key(mysqli $db, int $userId): string
{
	$stmt = $db->prepare('SELECT `clickserver_api_key` FROM `202_users` WHERE `user_id` = ?');
	if ($stmt === false) {
		throw new RuntimeException('Could not read the ClickServer key: ' . $db->error);
	}
	$stmt->bind_param('i', $userId);
	if (!$stmt->execute()) {
		$stmt->close();
		throw new RuntimeException('Could not read the ClickServer key: ' . $db->error);
	}
	$result = $stmt->get_result();
	if ($result === false) {
		$stmt->close();
		throw new RuntimeException('Could not read the ClickServer key: ' . $db->error);
	}
	$row = $result->fetch_assoc();
	$stmt->close();
	return (string) ($row['clickserver_api_key'] ?? '');
}

function clickserver_api_domain_act_deact($key, $csid, $method){
	// Restrict to the known endpoints before building the request path.
	if (!in_array($method, ['activate', 'deactivate'], true)) {
		return false;
	}
	//Initiate curl
	$ch = curl_init();
	// Will return the response, if false it print the response
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	// Set the url
	curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v1/'.$method.'/?apiKey='.rawurlencode($key).'&clickserverId='.rawurlencode($csid));
	// Execute
	$result=curl_exec($ch);

	$data = json_decode($result, true);

	if ($method == 'activate') {
		$success = $data['isActivationSuccess'];
	} else {
		$success = $data['isDeactivationSuccess'];
	}
			if ($data['isValidKey'] != 'true' || $success != 'true') {
				curl_close($ch);
				return false;
			}

		curl_close($ch);
		return true;
}

function clickserver_api_domain_list($key){

	//Initiate curl
	$ch = curl_init();
	// Will return the response, if false it print the response
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	// Set the url
	curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v1/list/?apiKey='.$key);
	// Execute
	$result=curl_exec($ch);

	$data = json_decode($result, true);

	curl_close($ch);
	return $data;
}

function clickserver_api_license($key){

	//Initiate curl
	$ch = curl_init();
	// Will return the response, if false it print the response
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	// Set the url
	curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v1/license/?apiKey='.$key);
	// Execute
	$result=curl_exec($ch);

	$data = json_decode($result, true);

	curl_close($ch);
	return $data;
}
