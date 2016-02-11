<?php

//grab the users date range preferences
$time = grab_timeframe();
$mysql['to'] = $db->real_escape_string($time['to']);
$mysql['from'] = $db->real_escape_string($time['from']);


//show real or filtered clicks
$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
$user_sql = "SELECT user_pref_show, user_cpc_or_cpv FROM 202_users_pref WHERE user_id=".$mysql['user_id'];
$user_result = _mysqli_query($user_sql); //($user_sql);
$user_row = $user_result->fetch_assoc();

if ($user_row['user_pref_show'] == 'all') { $click_flitered = ''; }
if ($user_row['user_pref_show'] == 'real') { $click_filtered = " AND click_filtered='0' "; }
if ($user_row['user_pref_show'] == 'filtered') { $click_filtered = " AND click_filtered='1' "; }
if ($user_row['user_pref_show'] == 'leads') { $click_filtered = " AND click_lead='1' "; }

if ($user_row['user_cpc_or_cpv'] == 'cpv')  $cpv = true;
else 										$cpv = false;


if ($cpv) {
	$decimal = 5;
} else {
	$decimal = 2;
}

$clicks_sql = "SELECT 
COALESCE(sum(clicks), 0) as clicks, 
COALESCE(sum(click_out), 0) as click_out,
COALESCE((COALESCE(sum(click_out), 0)/COALESCE(sum(clicks), 0))*100, 0) as ctr,
COALESCE(SUM(leads), 0) AS leads,
COALESCE((SUM(click_lead)/sum(clicks))*100, 0) as su_ratio,
COALESCE(AVG(2st.payout), 0) AS payout,
COALESCE(SUM(2st.income), 0) AS income,
COALESCE(SUM(2st.income)/sum(clicks), 0) as epc,
COALESCE(SUM(2st.cost), 0) AS cost,
COALESCE(SUM(2st.cost)/sum(clicks), 0) AS cpc,
COALESCE((SUM(2st.income)-SUM(2st.cost)), 0) AS net,
COALESCE(((SUM(2st.income)-SUM(2st.cost))/SUM(2st.cost)*100), 0) as roi 
FROM 202_dataengine as 2st
WHERE click_time >= '".$mysql['from']."'
AND click_time <= '".$mysql['to']."'
$click_filtered"; 

$clicks_result = _mysqli_query($clicks_sql);
$clicks_row = $clicks_result->fetch_array(MYSQLI_ASSOC);

?>