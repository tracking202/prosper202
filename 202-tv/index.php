<?php 
include_once(str_repeat("../", 1).'202-config/connect.php');

AUTH::require_user();

template_top('Prosper202 ClickServer TV202'); 
?>

<div class="row home">
 <?php 
//Initiate curl
$result= getData('https://my.tracking202.com/api/feeds/tv202?us='.$_SESSION['user_hash'].'?t202aid='.$_SESSION['user_cirrus_link']);

if ($result) {

    echo $result;

} else {
    echo "Sorry TV202 Feed Not Found: Please try again later";
} ?>
</div>
 <?php template_bottom(); ?>