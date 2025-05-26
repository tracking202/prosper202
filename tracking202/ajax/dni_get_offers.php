<?php
declare(strict_types=1);
include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php'); 
AUTH::require_user();

$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
$dni_id = $_GET['dni'];
$mysql['dni_id'] = $db->real_escape_string($dni_id);

if (isset($_GET['dni']) && isset($_GET['all_offers'])) {

	$sort = $_GET['column'];
	$filter = $_GET['filter'];

	$sql = "SELECT dni.networkId, dni.apiKey, dni.affiliateId, dni.name, 2u.install_hash FROM 202_dni_networks AS dni LEFT JOIN 202_users AS 2u USING(user_id) WHERE dni.user_id = '".$mysql['user_id']."' AND dni.id = '".$mysql['dni_id']."'";
	$results = $db->query($sql);
	if ($results->num_rows > 0) {
		$dni = $results->fetch_assoc();
		
		$sort_by = array('column' => '', 'by' => '');
		if (!empty($sort)) {
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

		$filter_by = array();
		if (!empty($filter)) {

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
		
		echo getDniOffers($dni['install_hash'], $dni['networkId'], $dni['apiKey'], $dni['affiliateId'], $_GET['offset'], $_GET['limit'], $sort_by, $filter_by);
	}
}

if (isset($_GET['dni']) && isset($_GET['get_offer']) && isset($_GET['offer_id'])) {
	$sql = "SELECT dni.networkId, dni.apiKey, dni.affiliateId, dni.name, 2u.install_hash FROM 202_dni_networks AS dni LEFT JOIN 202_users AS 2u USING(user_id) WHERE dni.user_id = '".$mysql['user_id']."' AND dni.id = '".$mysql['dni_id']."'";
	$results = $db->query($sql);
	if ($results->num_rows > 0) {
		$dni = $results->fetch_assoc();
		echo getDniOfferById($dni['install_hash'], $dni['networkId'], $dni['apiKey'], $dni['affiliateId'], $_GET['offer_id']);
	}
}

if (isset($_GET['dni']) && isset($_GET['request_offer_access']) && isset($_GET['offer_id']) && isset($_GET['type'])) {
	$sql = "SELECT dni.networkId, dni.apiKey, dni.affiliateId, dni.name, 2u.install_hash FROM 202_dni_networks AS dni LEFT JOIN 202_users AS 2u USING(user_id) WHERE dni.user_id = '".$mysql['user_id']."' AND dni.id = '".$mysql['dni_id']."'";
	$results = $db->query($sql);
	if ($results->num_rows > 0) {
		$dni = $results->fetch_assoc();
		echo requestDniOfferAccess($dni['install_hash'], $dni['networkId'], $dni['apiKey'], $dni['affiliateId'], $_GET['offer_id'], $_GET['type']);
	}
}

if (isset($_GET['dni']) && isset($_GET['offer_id']) && isset($_GET['submit_offer_questions'])) {
	$sql = "SELECT dni.networkId, dni.apiKey, dni.affiliateId, dni.name, 2u.install_hash FROM 202_dni_networks AS dni LEFT JOIN 202_users AS 2u USING(user_id) WHERE dni.user_id = '".$mysql['user_id']."' AND dni.id = '".$mysql['dni_id']."'";
	$results = $db->query($sql);
	if ($results->num_rows > 0) {
		$dni = $results->fetch_assoc();
		echo submitDniOfferAnswers($dni['install_hash'], $dni['networkId'], $dni['apiKey'], $dni['affiliateId'], $_GET['offer_id'], $_POST);
	}
}

if (isset($_GET['dni']) && isset($_GET['offer_id']) && isset($_GET['setup_offer'])) {
	$sql = "SELECT dni.networkId, dni.apiKey, dni.affiliateId, 2u.install_hash FROM 202_dni_networks AS dni LEFT JOIN 202_users AS 2u USING(user_id) WHERE dni.user_id = '".$mysql['user_id']."' AND dni.id = '".$mysql['dni_id']."'";
	$results = $db->query($sql);
	if ($results->num_rows > 0) {
		$dni = $results->fetch_assoc();
		$aff_network_sql = "SELECT aff_network_id FROM 202_aff_networks WHERE dni_network_id = '".$mysql['dni_id']."'";
		$aff_network_results = $db->query($aff_network_sql);
		$aff_network_row = $aff_network_results->fetch_assoc();
		$offerData = setupDniOffer($dni['install_hash'], $dni['networkId'], $dni['apiKey'], $dni['affiliateId'], 'USD', $_GET['offer_id'], $_GET['ddlci']);
		$data = json_decode($offerData, true);
		$data['aff_network_id'] = $aff_network_row['aff_network_id'];
		header('Content-Type: application/json');
		echo json_encode($data);
	}
}

?>