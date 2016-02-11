<?php include_once(substr(dirname( __FILE__ ), 0,-12) . '/202-config/connect.php'); 

AUTH::require_user();

header('location: '.get_absolute_url().'tracking202/overview/');