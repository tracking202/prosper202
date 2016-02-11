<?php 
$time_start = microtime(true);
set_time_limit(0);
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/class-dataengine.php');

$de = new DataEngine();
//$data=($de->setDirtyHour(1));

if($_GET['r']){ 

$range="0:00 -".$_GET ['r']. " days";
$start = strtotime ( $range);
}
else {
$start = strtotime ( "0:00 -1 days" );
}
$end = time();
define("ONEHOUR",3599);

for ($i=$start;$i<$end;$i+=ONEHOUR)
{
    $newEnd=$i+ONEHOUR;
    echo $i."-".$newEnd. $de->getSummary($i,$newEnd,'',$type)."<br>";
    flush();
  $i++;
}
//$de->processDirtyHours();
/* try {
    $database = DB::getInstance();
   $db = $database->getConnection();
} catch (Exception $e) {
    $db = false;
}

$sql="select * from nn";
$sqlr= $db->query($sql);
print_r($sqlr);
$stop = array( "clicks", "click_out", "leads", "payout", "income", "cost","click_time_to");
while($sqld= $sqlr->fetch_assoc()){

foreach ($sqld as $key => $value){
   
    if (!in_array($key, $stop)) {
        $bigvalue.="-".$value;
 }   

  
}
echo $bigvalue."<br>";
$bv[]=sha1($bigvalue);
$bigvalue = '';
}
print_r(array_count_values($bv));  */
//echo array_count_values(array_unique($bv));

$time_end = microtime(true);

//dividing with 60 will give the execution time in minutes other wise seconds
$execution_time = ($time_end - $time_start);

//execution time of the script
echo '<b>Total Execution Time:</b> '.$execution_time.' Secs';