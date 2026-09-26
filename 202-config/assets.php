<?php

declare(strict_types=1);

/**
 * Pinned front-end assets.
 *
 * Every third-party file the page shell loads is listed here with the version
 * it was taken from and, for files served from this install, the SHA-384 of
 * the exact bytes in the repository. tests/Api/V3/AssetManifestTest.php checks
 * each file against this list, so a silently re-minified or swapped file, or a
 * version that drifted from its filename, fails the build instead of shipping;
 * tests/Api/V3/ShellIsolationTest.php checks that the shell loads nothing
 * third-party that is not listed here, that nothing here goes unloaded, and
 * that no entry is a leftover of the classic stack.
 *
 * Entry shape:
 *   'path'    repo-relative file served from the install (mutually exclusive with 'url')
 *   'url'     external URL that carries its version in the path (no unpinned "latest")
 *   'version' the upstream version string
 *   'sha384'  base64 SHA-384 of the local file
 *   'banner'  a substring the file must contain (its own version banner), when it has one
 *   'patched' when the bytes are not an upstream release build, what differs;
 *             absent means the file is byte-identical to the release it names
 *
 * Highcharts stays on its vendor CDN because its licence does not allow the
 * library to be redistributed in this repository; the URL pins the version
 * instead of following the CDN's rolling "latest" build. That CDN requires a
 * Referer header and does not advertise CORS, so an integrity attribute would
 * block the script in browsers; the pinned version is the guarantee there.
 *
 * The 'legacy.*' entries of the classic shell (Bootstrap 3, Flat UI Pro and
 * the plugins written against them) were deleted with it in U8, with their
 * files, as were list.js and its fuzzy-search plugin, which only that shell
 * loaded. ShellIsolationTest refuses a 'legacy.' id and a file under a
 * legacy/ directory, so neither comes back by accident.
 *
 * To add or upgrade one:
 *   curl -sSL -o 202-js/vendor/<name>-<version>.min.js <release url>
 *   openssl dgst -sha384 -binary 202-js/vendor/<name>-<version>.min.js | openssl base64 -A
 */
return [
    'bootstrap.css' => [
        'path' => '202-css/vendor/bootstrap-5.3.8.min.css',
        'version' => '5.3.8',
        'sha384' => 'sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB',
        'banner' => 'Bootstrap  v5.3.8',
    ],
    'bootstrap.js' => [
        'path' => '202-js/vendor/bootstrap-5.3.8.bundle.min.js',
        'version' => '5.3.8',
        'sha384' => 'FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI',
        'banner' => 'Bootstrap v5.3.8',
    ],
    'bootstrap-icons.css' => [
        'path' => '202-css/vendor/bootstrap-icons-1.13.1/bootstrap-icons.min.css',
        'version' => '1.13.1',
        'sha384' => 'CK2SzKma4jA5H/MXDUU7i1TqZlCFaD4T01vtyDFvPlD97JQyS+IsSh1nI2EFbpyk',
        'banner' => 'Bootstrap Icons v1.13.1',
    ],
    'bootstrap-icons.woff2' => [
        'path' => '202-css/vendor/bootstrap-icons-1.13.1/fonts/bootstrap-icons.woff2',
        'version' => '1.13.1',
        'sha384' => 'xEoI56EFpIZiDZZKBZxsn3gO3u/FvXtOpHbtkMWmSdfzDw3x9XdVc3i70O9hm4SC',
    ],
    'bootstrap-icons.woff' => [
        'path' => '202-css/vendor/bootstrap-icons-1.13.1/fonts/bootstrap-icons.woff',
        'version' => '1.13.1',
        'sha384' => 'IYfD9pNP/nesQsPyYtTdGCb4uhEWUmNF8GxaCvqcJFH+Of3c1b0VbH6hdHUonDSC',
    ],
    'jquery.js' => [
        'path' => '202-js/vendor/jquery-3.7.1.min.js',
        'version' => '3.7.1',
        'sha384' => '1H217gwSVyLSIfaLxHbE7dRb3v4mYCKbpQvzx0cegeju1MVsGrX5xXxAvs/HgeFs',
        'banner' => 'jQuery v3.7.1',
    ],
    'highcharts.js' => [
        'url' => 'https://code.highcharts.com/11.4.8/highcharts.js',
        'version' => '11.4.8',
    ],

    // Framework-free.
    'tablesort.js' => [
        'path' => '202-js/vendor/tablesort-3.0.2.min.js',
        'version' => '3.0.2',
        'sha384' => '3Ild5rUh1H8vi7yOiKeJP7BJBNQv4oAb4rK1zpF2tLJQQtaGo9v+7/KOdcEzKEI/',
        'banner' => 'tablesort v3.0.2',
        'patched' => 'the 3.0.2 release build with its tablesort.number sort appended',
    ],
];
