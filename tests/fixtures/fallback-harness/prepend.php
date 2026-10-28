<?php

// auto_prepend_file for FallbackRedirectScriptsTest: the request the script sees.
$p202Request = json_decode((string) getenv('P202_HARNESS'), true, 512, JSON_THROW_ON_ERROR);
$_GET = $p202Request['get'];
$_COOKIE = $p202Request['cookie'];
$_SERVER = array_merge($_SERVER, $p202Request['server']);
unset($p202Request);
