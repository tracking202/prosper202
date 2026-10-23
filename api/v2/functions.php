<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy/functions.php';

function getAuth($db, $variables): mixed {
	$mysql['api_key'] = $db->real_escape_string((string) ($variables['apikey'] ?? ''));
	// Join 202_users so a soft-deleted user's key stops authenticating, exactly
	// as api/v3/Auth.php does. Deleting a user must revoke access on EVERY API
	// version, not just the newest one; so must deactivating one (below).
	$key_sql = "SELECT 	k.*, u.`user_active`
				FROM   	`202_api_keys` k
				INNER JOIN `202_users` u ON u.`user_id` = k.`user_id`
				WHERE  	k.`api_key`='".$mysql['api_key']."' AND u.`user_deleted` = 0";
	$key_result = _mysqli_query($db, $key_sql);
	if ($key_result === false) {
		return legacy_api_database_error($db);
	}
	$key_row = $key_result->fetch_assoc();
    // A failed fetch is false; read as no row, the key would be refused as
    // the wrong thing (a deactivated account).
    if ($key_row === false) {
        return legacy_api_database_error($db);
    }

	if($key_result->num_rows > 0) {

		// A deactivated user's key is refused, as the sign-in refuses them
		// (`user_active = 1`, functions-auth.php) and api/v3/Auth.php does:
		// this read user_deleted alone, so a user turned off in Account ›
		// Users kept reading reports with every key they held.
		if ((string) ($key_row['user_active'] ?? '') !== '1') {
			return ['msg' => 'The account this API key belongs to is deactivated', 'error' => true, 'status' => 401];
		}

		// A v3-scoped key is an attenuated credential. This legacy API
		// predates scoping and cannot enforce it, so refuse the key
		// outright rather than granting more than its scope allows.
		$legacy_key_scope = strtolower(trim((string) ($key_row['scope'] ?? '')));
		if ($legacy_key_scope !== '' && $legacy_key_scope !== '*') {
			return ['msg' => 'This API key is scoped and only valid for the v3 API', 'error' => true, 'status' => 403];
		}

		$mysql['user_id'] = $db->real_escape_string($key_row['user_id']);
		$user_sql = "SELECT 	`user_timezone`
					FROM   	`202_users`
					WHERE  	`user_id`='".$mysql['user_id']."'";
		$user_result = _mysqli_query($db, $user_sql);
		if ($user_result === false) {
			return legacy_api_database_error($db);
		}
		$user_row = $user_result->fetch_assoc();
        // A failed fetch read as no row answered every report in UTC.
        if ($user_row === false) {
            return legacy_api_database_error($db);
        }
        if ($user_row === null) {
            return ['msg' => 'Unauthorized', 'error' => true, 'status' => 401];
        }
		return [
			'msg' => 'Authorized',
			'error' => false,
			'status' => 202,
			'user_id' => (int) $key_row['user_id'],
			'user_timezone' => (string) (($user_row['user_timezone'] ?? 'UTC')),
		];
		//return array('msg' => 'Authorized', 'error' => false, 'status' => 202);
	} else {
		return ['msg' => 'Unauthorized', 'error' => true, 'status' => 401];
	}
}

function showCategories($db, $vars, $user = null): void {
    foreach ($vars as $key=>$var)
        $vars[$key]=$db->real_escape_string($var);
    //$vars = $db->real_escape_string($vars);
  //  print_r($vars);

    if($vars['action']=="list")
        listCategories($db,$user);
   // print_r($data);
 
   
}

function listCategories($db,$user): void {
    echo legacy_api_json(legacyCategoryList($db, $user));
}

/** The account's categories, or the database error for a failed read. */
function legacyCategoryList($db, $user): array
{
    $cat = ['categories' => []];
    // categories/index.php hands the user id as an int.
    $mysql['user_id'] = $db->real_escape_string((string) $user);
    $aff_network_sql = "SELECT * FROM `202_aff_networks` WHERE `user_id`='".$mysql['user_id']."' AND `aff_network_deleted`='0' ORDER BY `aff_network_name` ASC";
    // `or die()` answered an empty 200, which a client reads as no categories.
    $aff_network_result = $db->query($aff_network_sql);
    if ($aff_network_result === false) {
        return legacy_api_database_error($db);
    }
    while (($aff_network_row = $aff_network_result->fetch_array(MYSQLI_ASSOC)) !== null) {
        if ($aff_network_row === false) {
            return legacy_api_database_error($db);
        }

        $html['aff_network_name'] = htmlentities((string)($aff_network_row['aff_network_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $html['aff_network_id'] = htmlentities((string)($aff_network_row['aff_network_id'] ?? ''), ENT_QUOTES, 'UTF-8');
        $cat['categories'][] = ['category_id' => $html['aff_network_id'], 'category_name' => $html['aff_network_name']];
    }

    return $cat;
}
