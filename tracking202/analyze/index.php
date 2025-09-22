<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-20) . '/202-config/connect.php');

AUTH::require_user();

header('location: '.get_absolute_url().'tracking202/analyze/keywords.php');

