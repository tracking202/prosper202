<?php

include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');
session_destroy();

header('location: /');