<?php

function addConversionLog($click_id, $txid, $campaign_id, $click_payout_added, $user_id, $click_time, $ip, $user_agent, $conv_time = null, $type)
{
    $database = DB::getInstance();
    $db = $database->getConnection();
    
    if ($conv_time == null) {
        $conv_time = time();
    }
    
    $mysql['txid'] = $db->real_escape_string($txid);
    $click_time_to_date = new DateTime(date('Y-m-d H:i:s', $click_time));
    $conv_time_to_date = new DateTime(date('Y-m-d H:i:s', $conv_time));
    $diff = $click_time_to_date->diff($conv_time_to_date);
    $mysql['time_difference'] = $db->real_escape_string($diff->d . ' days, ' . $diff->h . ' hours, ' . $diff->i . ' min and ' . $diff->s . ' sec');
    $mysql['ip'] = $db->real_escape_string($ip->address);
    $mysql['user_agent'] = $db->real_escape_string($user_agent);
    $mysql['campaign_id'] = $db->real_escape_string($campaign_id);
    $mysql['click_payout_added'] = $db->real_escape_string($click_payout_added);
    $mysql['user_id'] = $db->real_escape_string($user_id);
    $mysql['click_time'] = $db->real_escape_string($click_time);
    $mysql['type'] = $db->real_escape_string($type);
    $log_sql = "INSERT INTO 202_conversion_logs
                SET conv_id = DEFAULT,
                click_id = '" . $click_id . "',
                transaction_id = '" . $mysql['txid'] . "',
                campaign_id = '" . $mysql['campaign_id'] . "',
                click_payout = '" . $mysql['click_payout_added'] . "',
                user_id = '" . $mysql['user_id'] . "',
                click_time = '" . $mysql['click_time'] . "',
                conv_time = '" . $conv_time . "',
                time_difference = '" . $mysql['time_difference'] . "',
                ip = '" . $mysql['ip'] . "',
                pixel_type = '" . $mysql['type'] . "',
                user_agent = '" . $mysql['user_agent'] . "'";
    $db->query($log_sql);
}

function ignoreDuplicates()
{
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            $the_request = & $_GET;
            break;
        case 'POST':
            $the_request = & $_POST;
            break;
    }
    
    $database = DB::getInstance();
    $db = $database->getConnection();
    
    $click_id = $db->real_escape_string($the_request['subid']);
    $txid = $db->real_escape_string($the_request['t202txid']);
    $dedupe = $db->real_escape_string($the_request['t202dedupe']);
    
    if ($txid == '')
        $txid_sql = "IS NULL";
    else
        $txid_sql = "= '" . $db->real_escape_string($txid) ."'";
    
    $dedupe_sql = "select * from 202_conversion_logs where click_id=".$click_id." and transaction_id " . $txid_sql;
    $dedupe_result = $db->query($dedupe_sql);
    if ($dedupe == '1' && $db->affected_rows > 0) {
        return true;
    } else {
        return false;
    }
}

?>