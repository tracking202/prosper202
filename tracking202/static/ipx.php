<?php

/**
 * The impression pixel: answers a transparent 1x1 GIF and records nothing.
 *
 * It used to INSERT a row into 202_clicks_impressions and set a p202_ipx
 * cookie, which the click recorders then used to link the impression to the
 * click. No installer or upgrade creates that table (it is not in
 * 202-config/Database/Tables, and NamedTablesExistTest now refuses SQL that
 * names a table nothing creates), nothing in the product hands out an
 * ipx.php URL, and nothing reads the table: the INSERT failed on every
 * request, so no cookie was ever set, and the linking UPDATE failed and
 * logged on every landing-page click. The writes are gone. The URL still
 * answers the image, so a pixel embedded somewhere renders as nothing
 * rather than as a broken image.
 */

declare(strict_types=1);

header('Content-Type: image/gif');
header('Content-Length: 43');
header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');
header('Expires: Sun, 03 Feb 2008 05:00:00 GMT'); // Date in the past
header('Pragma: no-cache');

echo base64_decode('R0lGODlhAQABAIAAAAAAAAAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==');
