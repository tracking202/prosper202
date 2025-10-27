<?php

declare(strict_types=1);

include_once str_repeat('../', 1) . '202-config/connect.php';

AUTH::require_user();

header('Location: ' . get_absolute_url() . '202-account/attribution.php');
exit;
