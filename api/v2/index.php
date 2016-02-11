<?php

require '/Slim/Slim.php';
\Slim\Slim::registerAutoloader();

$app = new \Slim\Slim();
$app->get('/foo', function () {
    echo "Foo!";
});
$app->run();

