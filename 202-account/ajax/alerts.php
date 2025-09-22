<?php
declare(strict_types=1);
include_once(str_repeat("../", 2).'202-config/connect.php');
include_once(str_repeat("../", 2).'202-config/functions-rss.php');
include_once(str_repeat("../", 2).'202-config/DashboardDataManager.class.php');

AUTH::require_user();

$showAlerts = false;
$dontShow = [];
$html = [];

// Get cached alerts from local database
$dataManager = new DashboardDataManager();
$alerts = $dataManager->getContent('alerts', 3);

// If no cached alerts, exit silently
if (empty($alerts)) {
    exit;
}

// Check which alerts have been marked as seen
foreach ($alerts as $alert) {
    $external_id = $alert['external_id'];
    if ($external_id) {
        $mysql['prosper_alert_id'] = $db->real_escape_string($external_id);
        $sql = "SELECT COUNT(*) AS count FROM 202_alerts WHERE prosper_alert_id='{$mysql['prosper_alert_id']}' AND prosper_alert_seen='1'";
        $result = _mysqli_query($sql, $db);
        $row = $result->fetch_assoc();
        if ($row['count']) {
            $dontShow[$external_id] = true;
        } else {
            $showAlerts = true;
        }
    } else {
        // If no external_id, show the alert
        $showAlerts = true;
    }
}

// If no alerts to show, exit
if (!$showAlerts) {
    exit;
}

// Display alerts
foreach ($alerts as $alert) {
    $external_id = $alert['external_id'] ?? '';
    
    // Skip if marked as don't show
    if (isset($dontShow[$external_id]) && $dontShow[$external_id]) {
        continue;
    }
    
    // Calculate time ago
    $published_time = $alert['published_at'] ? strtotime((string) $alert['published_at']) : time();
    $item_time = human_time_diff($published_time, time()) . " ago";
    
    // Sanitize output
    $html['time'] = htmlentities($item_time);
    $html['prosper_alert_id'] = htmlentities($external_id);
    $html['title'] = htmlentities($alert['title'] ?? '');
    $html['description'] = nl2br(htmlentities($alert['description'] ?? '')); ?>

    <div id="prosper-alerts" class="alert alert-error" data-alertid="<?php echo $html['prosper_alert_id'];?>">
        <button type="button" class="close fui-cross" data-dismiss="alert"></button>
        <strong><?php echo $html['title']. " - " .$html['time'];?></strong><br/>
        <span class="small"><?php echo $html['description'];?></span>
    </div>

<?php } ?>