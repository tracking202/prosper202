<?php
ini_set('memory_limit', '64M');
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');

AUTH::require_user();

template_top('Administration',NULL,NULL,NULL); 
//do backup 
backup_tables();