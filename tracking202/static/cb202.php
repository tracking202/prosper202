<?php

include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect2.php');


function ipnVerification($userkey) {
        $secretKey = $userkey;
        $pop = "";
        $ipnFields = array();
        foreach ($_POST as $key => $value) {
            if ($key == "cverify") {
                continue;
            }
            $ipnFields[] = $key;
        }
        sort($ipnFields);
        foreach ($ipnFields as $field) {
            // if Magic Quotes are enabled $_POST[$field] will need to be
            // un-escaped before being appended to $pop
            $pop = $pop . $_POST[$field] . "|";
        }
        $pop = $pop . $secretKey;
        $calcedVerify = sha1(mb_convert_encoding($pop, "UTF-8"));
        $calcedVerify = strtoupper(substr($calcedVerify,0,8));
        return $calcedVerify == $_POST["cverify"];
}

$mysql['user_id'] = 1;

$user_sql = "SELECT cb_key
             FROM 202_users_pref
             WHERE user_id='".$mysql['user_id']."'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();


if ($_POST['ctransaction'] == 'TEST') {
    if (ipnVerification($user_row['cb_key'])) {

            $user_sql = "UPDATE 202_users_pref
                         SET cb_verified=1
                         WHERE user_id='".$mysql['user_id']."'";
            $user_results = $db->query($user_sql);
            $user_row = $user_results->fetch_assoc();
    }
} 

if ($_POST['ctransaction'] == 'SALE') {

        if (!$_POST['ctid']) die();

            $click_id = $_POST['ctid'];
            $mysql['click_id'] = $db->real_escape_string($click_id);
            $mysql['use_pixel_payout'] = 0;

        if (is_numeric($mysql['click_id'])) {

            if ($_POST['caccountamount'] && is_numeric($_POST['caccountamount'])) {
                $amount = $_POST['caccountamount'] / 100;
                $mysql['click_payout'] = $db->real_escape_string($amount);
                $mysql['use_pixel_payout'] = 1;
            }

            if (ipnVerification($user_row['cb_key'])) {
           
                $click_sql = "
                    UPDATE
                        202_clicks 
                    SET
                        click_lead='1', 
                        click_filtered='0'
                ";
                if ($mysql['use_pixel_payout']==1) {
                    $click_sql .= "
                        , click_payout='".$mysql['click_payout']."'
                    ";
                }
                $click_sql .= "
                    WHERE
                        click_id='".$mysql['click_id']."'
                ";
                delay_sql($db, $click_sql);

                $click_sql = "
                    UPDATE
                        202_clicks_spy 
                    SET
                        click_lead='1',
                        click_filtered='0'
                ";
                if ($mysql['use_pixel_payout']==1) {
                    $click_sql .= "
                        , click_payout='".$mysql['click_payout']."'
                    ";
                }
                $click_sql .= "
                    WHERE
                        click_id='".$mysql['click_id']."'
                ";
                delay_sql($db, $click_sql);
            }

        }

} 




?>