<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-17) . '/202-config/connect.php');
AUTH::require_user();

/*
 * Setup › Campaigns' offer browser for a Direct Network Integration: every
 * branch reaches my.tracking202.com with this account's network key.
 *
 * Two kinds of branch. Reading (all_offers, get_offer) asks the network for
 * offers and changes nothing anywhere, so a forged request gains nothing it
 * can read (the answer goes to the forging page's opaque response) and
 * these stay plain GETs. Acting (request_offer_access, submit_offer_questions,
 * setup_offer) makes the network do something on the account's behalf: ask
 * for access, send answers, set an offer up. Those are POSTs and take the
 * session token, like every other Setup POST (#164, error pattern #5): as
 * bare GETs, an <img> on any page the user visited could fire them.
 */

$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
$dni_id = (string) ($_GET['dni'] ?? '');
$mysql['dni_id'] = $db->real_escape_string($dni_id);

/** Refuse an acting branch that is not a POST carrying the session token. */
function p202_dni_require_token(): void
{
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
		|| !hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? ''))) {
		http_response_code(403);
		die('Invalid token, please reload the page and try again.');
	}
}

/**
 * This account's integration row for the requested id, or null when it has
 * none by that id. A query that fails is an error, not "none" (#1).
 *
 * @return array<string, mixed>|null
 */
function p202_dni_row(mysqli $db, array $mysql): ?array
{
	$sql = "SELECT dni.networkId, dni.apiKey, dni.affiliateId, dni.name, 2u.install_hash FROM 202_dni_networks AS dni LEFT JOIN 202_users AS 2u USING(user_id) WHERE dni.user_id = '".$mysql['user_id']."' AND dni.id = '".$mysql['dni_id']."'";
	$results = $db->query($sql);
	if (!$results instanceof mysqli_result) {
		record_mysql_error($db, $sql);
	}
	return $results->fetch_assoc();
}

/** An offer id or access type as the network names them, or a 400. */
function p202_dni_token_param(string $name): string
{
	$value = $_GET[$name] ?? null;
	if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $value) !== 1) {
		http_response_code(400);
		die('That ' . str_replace('_', ' ', $name) . ' could not be read.');
	}
	return $value;
}

if (isset($_GET['dni']) && isset($_GET['all_offers'])) {

	$sort = $_GET['column'] ?? null;
	$filter = $_GET['filter'] ?? null;

	$dni = p202_dni_row($db, $mysql);
	if ($dni !== null) {

		$sort_by = ['column' => '', 'by' => ''];
		if (!empty($sort) && is_array($sort)) {
			foreach ($sort as $key => $value) {
				if ($sort[$key] == 0) {
					$sort_by['by'] = 'ASC';
				} else {
					$sort_by['by'] = 'DESC';
				}

				if ($key == 0) {
					$sort_by['column'] = 'id';
				}

				if ($key == 1) {
					$sort_by['column'] = 'name';
				}

				if ($key == 2) {
					$sort_by['column'] = 'default_payout';
				}

				if ($key == 3) {
					$sort_by['column'] = 'payout_type';
				}

				if ($key == 5) {
					$sort_by['column'] = 'require_approval';
				}
			}
		}

		$filter_by = [];
		if (!empty($filter) && is_array($filter)) {

			foreach ($filter as $key => $value) {
				if ($key == 0) {
					$filter_by['id'] = $value;
				}

				if ($key == 1) {
					$filter_by['name'] = $value;
				}

				if ($key == 2) {
					$filter_by['default_payout'] = $value;
				}

				if ($key == 3) {
					$filter_by['payout_type'] = $value;
				}
			}
		}

		// Offset and limit are path segments of the network's URL.
		echo getDniOffers($dni['install_hash'], $dni['networkId'], $dni['apiKey'], $dni['affiliateId'], max(0, (int) ($_GET['offset'] ?? 0)), max(1, min(100, (int) ($_GET['limit'] ?? 25))), $sort_by, $filter_by);
	}
}

if (isset($_GET['dni']) && isset($_GET['get_offer']) && isset($_GET['offer_id'])) {
	$offerId = p202_dni_token_param('offer_id');
	$dni = p202_dni_row($db, $mysql);
	if ($dni !== null) {
		echo getDniOfferById($dni['install_hash'], $dni['networkId'], $dni['apiKey'], $dni['affiliateId'], $offerId);
	}
}

if (isset($_GET['dni']) && isset($_GET['request_offer_access']) && isset($_GET['offer_id']) && isset($_GET['type'])) {
	p202_dni_require_token();
	$offerId = p202_dni_token_param('offer_id');
	$type = p202_dni_token_param('type');
	$dni = p202_dni_row($db, $mysql);
	if ($dni !== null) {
		echo requestDniOfferAccess($dni['install_hash'], $dni['networkId'], $dni['apiKey'], $dni['affiliateId'], $offerId, $type);
	}
}

if (isset($_GET['dni']) && isset($_GET['offer_id']) && isset($_GET['submit_offer_questions'])) {
	// Submits answers to the network on the user's behalf. The page posts
	// through jQuery, whose prefilter attaches the token, and the token is
	// not forwarded to the network with the answers.
	p202_dni_require_token();
	$offerId = p202_dni_token_param('offer_id');
	$answers = $_POST;
	unset($answers['token']);
	$dni = p202_dni_row($db, $mysql);
	if ($dni !== null) {
		echo submitDniOfferAnswers($dni['install_hash'], $dni['networkId'], $dni['apiKey'], $dni['affiliateId'], $offerId, $answers);
	}
}

if (isset($_GET['dni']) && isset($_GET['offer_id']) && isset($_GET['setup_offer'])) {
	p202_dni_require_token();
	$offerId = p202_dni_token_param('offer_id');
	$dni = p202_dni_row($db, $mysql);
	if ($dni !== null) {
		header('Content-Type: application/json');
		$aff_network_sql = "SELECT aff_network_id FROM 202_aff_networks WHERE dni_network_id = '".$mysql['dni_id']."' AND user_id = '".$mysql['user_id']."' AND aff_network_deleted = 0";
		$aff_network_results = $db->query($aff_network_sql);
		if (!$aff_network_results instanceof mysqli_result) {
			record_mysql_error($db, $aff_network_sql);
		}
		$aff_network_row = $aff_network_results->fetch_assoc();
		if ($aff_network_row === null) {
			// The integration's campaign category is what the offer is set
			// up under; without it the form would be filled with no category.
			http_response_code(409);
			die(json_encode(['error' => 'This network has no campaign category yet. Open Account › Integrations and save the network again.']));
		}
		$offerData = setupDniOffer($dni['install_hash'], $dni['networkId'], $dni['apiKey'], $dni['affiliateId'], 'USD', $offerId, (string) ($_GET['ddlci'] ?? ''));
		$data = json_decode((string) $offerData, true);
		if (!is_array($data)) {
			// Not an answer the form can be filled from (#4): say so rather
			// than hand the page a near-empty object that fills nothing.
			http_response_code(502);
			die(json_encode(['error' => 'The network did not answer with the offer\'s details. Try again in a minute.']));
		}
		$data['aff_network_id'] = $aff_network_row['aff_network_id'];
		$encoded = json_encode($data);
		if ($encoded === false) {
			http_response_code(502);
			die('{"error":"The network\'s answer could not be passed on."}');
		}
		echo $encoded;
	}
}
