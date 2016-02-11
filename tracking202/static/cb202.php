<?php

include_once(substr(dirname( __FILE__ ), 0,-19) . '/202-config/connect.php');
include_once(substr(dirname( __FILE__ ), 0,-19) . '/202-config/class-dataengine-slim.php');

$mysql['user_id'] = 1;

$slack = false;
$mysql['user_id'] = 1;
$user_sql = "SELECT 2u.user_name as username, 2up.user_slack_incoming_webhook as url, 2up.cb_key AS cb_key FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '".$mysql['user_id']."'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();

if (!empty($user_row['url'])) 
    $slack = new Slack($user_row['url']);

if(function_exists("mcrypt_encrypt")) {
    $message = json_decode(file_get_contents('php://input'));
    $encrypted = $message->{'notification'};
    $iv = $message->{'iv'};
    $decrypted = trim(mcrypt_decrypt(MCRYPT_RIJNDAEL_128,
                 substr(sha1($user_row['cb_key']), 0, 32),
                 base64_decode($encrypted),
                 MCRYPT_MODE_CBC,
                 base64_decode($iv)), "\0..\32");
    $order = json_decode($decrypted, true);

    if ($order['transactionType'] == 'TEST') {
        $user_sql = "UPDATE 202_users_pref
                     SET cb_verified=1
                     WHERE user_id='".$mysql['user_id']."'";
        $user_results = $db->query($user_sql);

        if ($slack) 
            $slack->push('cb_key_verified', array());

    } else if($order['transactionType'] == 'SALE') {
        $mysql['click_id'] = $db->real_escape_string($order['trackingCodes'][0]);
        $mysql['click_payout'] = $db->real_escape_string($order['totalAccountAmount']);

        $cpa_sql = "SELECT 202_cpa_trackers.tracker_id_public, 202_trackers.click_cpa FROM 202_cpa_trackers LEFT JOIN 202_trackers USING (tracker_id_public) WHERE click_id = '".$mysql['click_id']."'";
        $cpa_result = $db->query($cpa_sql);
        $cpa_row = $cpa_result->fetch_assoc();

        $mysql['click_cpa'] = $db->real_escape_string($cpa_row['click_cpa']);
                
        if ($mysql['click_cpa']) {
            $sql_set = "click_cpc='".$mysql['click_cpa']."', click_lead='1', click_filtered='0', click_payout='".$mysql['click_payout']."'";
        } else {
            $sql_set = "click_lead='1', click_filtered='0', click_payout='".$mysql['click_payout']."'";
        }

        $click_sql = "
            UPDATE
                202_clicks 
            SET
                ".$sql_set."
            WHERE
                click_id='".$mysql['click_id']."'    
        ";

        $db->query($click_sql);

        $click_sql = "
            UPDATE
                202_clicks_spy 
            SET
                ".$sql_set."
            WHERE
                click_id='".$mysql['click_id']."'    
        ";
        $db->query($click_sql);

        //set dirty hour
        $de = new DataEngine();
        $data=($de->setDirtyHour($mysql['click_id']));
    }

} else {
    die("Missing Mcrypt!");
}

?>