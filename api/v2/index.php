<?php
declare(strict_types=1);
require '/Slim/Slim.php';
\Slim\Slim::registerAutoloader();

$app = new \Slim\Slim();
$app->get('/foo', function () {
    echo "Foo!";
});
$app->run();

