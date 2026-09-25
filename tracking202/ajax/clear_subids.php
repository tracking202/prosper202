<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-17) . '/202-config/connect.php');

AUTH::require_user();

/**
 * Clear every converted click the query selects, through the ledger: each
 * click's conversions are soft-deleted and its value recomputed from what is
 * left (MysqlConversionRepository::clearClicks). This page used to set
 * click_lead = 0 and leave every conversion row counting, so the rows and
 * the click disagreed from then on.
 *
 * @return int The number of clicks cleared.
 */
function p202ClearSubidsThrough(mysqli $db, int $userId, string $selectSql, int $scopeId): int
{
	set_time_limit(0);
	$conn = new \Prosper202\Database\Connection($db);
	$stmt = $conn->prepareWrite($selectSql);
	$conn->bind($stmt, 'ii', [$userId, $scopeId]);
	$clickIds = array_map(static fn (array $row): int => (int) $row['click_id'], $conn->fetchAll($stmt));

	$repo = new \Prosper202\Conversion\MysqlConversionRepository($conn);
	$cleared = 0;
	foreach (array_chunk($clickIds, 500) as $chunk) {
		$cleared += $repo->clearClicks($userId, $chunk);
	}

	return $cleared;
}

	// Require a valid session token for this state-changing request.
	if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? ''))) {
		die();
	}

	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
	
	if (!isset($_POST['aff_network_id']) || $_POST['aff_network_id'] == 0) { 
		$error['clear_subids'] = '<div class="error"><small><span class="fui-alert"></span>You have to at least select an affiliate network to clear out</small></div>'; 
	}
	$mysql['aff_network_id'] = $db->real_escape_string(isset($_POST['aff_network_id']) ? (string)$_POST['aff_network_id'] : '0');
	
	if (isset($error)){ 
		echo $error['clear_subids'];  
		die();
	}
	
	
	if (!isset($error)) { 

		$de = [];
		$de['ppc_account_id'] = 0;

		if (isset($_POST['aff_campaign_id']) && (string)$_POST['aff_campaign_id'] !== '0') {
			$mysql['aff_campaign_id'] = $db->real_escape_string((string)$_POST['aff_campaign_id']);
			$clicks = p202ClearSubidsThrough(
				$db,
				(int) $_SESSION['user_id'],
				"SELECT c.click_id FROM 202_clicks AS c
				 WHERE c.user_id = ? AND c.aff_campaign_id = ?
				 AND (c.click_lead = 1 OR EXISTS (SELECT 1 FROM 202_conversion_logs AS cl WHERE cl.click_id = c.click_id AND cl.deleted = 0))",
				(int) $mysql['aff_campaign_id']
			);

			$de['aff_campaign_id'] = $mysql['aff_campaign_id'];

			$click_sql = "
				SELECT click_time
				FROM 202_clicks
				WHERE user_id='".$mysql['user_id']."'
				AND aff_campaign_id='".$mysql['aff_campaign_id']."'
				LIMIT 1
			";
			$click_result = $db->query($click_sql) or record_mysql_error($click_sql);
			$row = $click_result ? $click_result->fetch_assoc() : null;
			$de['user_id'] = $mysql['user_id'];
			$de['click_time_from'] = $row['click_time'] ?? null;
			$de['click_time_to'] = time();

		} else {
			
			$clicks = p202ClearSubidsThrough(
				$db,
				(int) $_SESSION['user_id'],
				"SELECT c.click_id FROM 202_clicks AS c
				 INNER JOIN 202_aff_campaigns AS ac ON ac.aff_campaign_id = c.aff_campaign_id
				 WHERE c.user_id = ? AND ac.aff_network_id = ?
				 AND (c.click_lead = 1 OR EXISTS (SELECT 1 FROM 202_conversion_logs AS cl WHERE cl.click_id = c.click_id AND cl.deleted = 0))",
				(int) $mysql['aff_network_id']
			);

			$de['aff_campaign_id'] = 0;

			$click_sql = "
				SELECT 2c.click_time 
				FROM 202_clicks AS 2c
				INNER JOIN 202_aff_campaigns AS 2ac ON (
					2c.aff_campaign_id = 2ac.aff_campaign_id
					AND 2ac.aff_network_id='".$mysql['aff_network_id']."'
				)
				WHERE 2c.user_id='".$mysql['user_id']."'
			";
			$click_result = $db->query($click_sql) or record_mysql_error($click_sql);
			$row = $click_result ? $click_result->fetch_assoc() : null;

			$de['user_id'] = $mysql['user_id'];
			$de['click_time_from'] = $row['click_time'] ?? null;
			$de['click_time_to'] = time();
		}

		$dirty_hours_sql = "INSERT IGNORE INTO 
							202_dirty_hours 
							SET 
							ppc_account_id = '".$de['ppc_account_id']."', 
							aff_campaign_id = '".$de['aff_campaign_id']."',
							aff_network_id = '".$mysql['aff_network_id']."',
							user_id = '".$de['user_id']."',
							click_time_from = '".$de['click_time_from']."',
							click_time_to = '".$de['click_time_to']."'";

		if ($clicks) {
			$db->query($dirty_hours_sql) or record_mysql_error($dirty_hours_sql);
		}

		echo "<div class=\"success\"><span class=\"fui-check-inverted\"></span><small>You have reset <strong>$clicks</strong> subids!<br/>You can now re-upload your subids.</small></div>";
		
	}