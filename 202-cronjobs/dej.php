<?php 

include_once(substr(dirname( __FILE__ ), 0,-13) . '/202-config/connect.php'); 
include_once(substr(dirname( __FILE__ ), 0,-13) . '/202-config/class-dataengine.php');
set_time_limit(0);

$snippet = "";
$start =$_GET['s'];
//$end =$_GET['e'];

$de = new DataEngine();
$de->getSummary($start, $start+3599, $snippet, 1, true);