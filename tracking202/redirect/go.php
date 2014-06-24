<?php

  //Simple LP redirect
  if (isset($_GET['lpip']) && !empty($_GET['lpip'])) {
    if (isset($_COOKIE['tracking202outbound'])) {
      $tracking202outbound = $_COOKIE['tracking202outbound'];     
    } else {
      $tracking202outbound = 'http://'.$_SERVER['HTTP_HOST'].'/tracking202/redirect/lp.php?lpip='.$_GET['lpip'].'&pci='.$_COOKIE['tracking202pci'];
    }
    
    header('location: '.$tracking202outbound);
  }

  //Advanced LP redirect
  if (isset($_GET['acip']) && !empty($_GET['acip'])) {

    $tracking202outbound = 'http://'.$_SERVER['HTTP_HOST'].'/tracking202/redirect/off.php?acip='.$_GET['acip'].'&pci='.$_COOKIE['tracking202pci']; 
 
    header('location: '.$tracking202outbound);
  }  

  die("Missing LPIP or ACIP variable!");
  
?>