<?php include_once(substr(dirname( __FILE__ ), 0,-20) . '/202-config/connect.php'); 

AUTH::require_user();

header('location: '.get_absolute_url().'tracking202/analyze/keywords.php');

