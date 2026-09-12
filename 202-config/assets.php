<?php

declare(strict_types=1);

/**
 * Pinned front-end assets.
 *
 * Every third-party file the page shell loads is listed here with the version
 * it was taken from and, for files served from this install, the SHA-384 of
 * the exact bytes in the repository. tests/Api/V3/AssetManifestTest.php checks
 * each file against this list, so a silently re-minified or swapped file, or a
 * version that drifted from its filename, fails the build instead of shipping.
 *
 * Entry shape:
 *   'path'    repo-relative file served from the install (mutually exclusive with 'url')
 *   'url'     external URL that carries its version in the path (no unpinned "latest")
 *   'version' the upstream version string
 *   'sha384'  base64 SHA-384 of the local file
 *   'banner'  a substring the file must contain (its own version banner), when it has one
 *
 * Highcharts stays on its vendor CDN because its licence does not allow the
 * library to be redistributed in this repository; the URL pins the version
 * instead of following the CDN's rolling "latest" build. That CDN requires a
 * Referer header and does not advertise CORS, so an integrity attribute would
 * block the script in browsers; the pinned version is the guarantee there.
 *
 * The 'legacy.*' entries are the files the classic shell used to load unversioned
 * from a CloudFront bucket; they are vendored so the bucket is no longer a
 * dependency, and they are deleted with the classic shell.
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

    // Classic shell only. Deleted with it.
    'legacy.jquery.js' => [
        'path' => '202-js/vendor/legacy/jquery-1.11.2.min.js',
        'version' => '1.11.2',
        'sha384' => 'TvgHo+v6a3gplOFufwqtF79SUbDePG7gV3aD+EBtTfKkVPzEfXXTURqZ0hnp0/mR',
        'banner' => 'jQuery v1.11.2',
    ],
    'legacy.jquery-ui.js' => [
        'path' => '202-js/vendor/legacy/jquery-ui-1.11.2.min.js',
        'version' => '1.11.2',
        'sha384' => 'IvbGQHn8kzhI5WJ08ahOTFvlAv0Tm7ELkg+2XkIaylUDhvHKQ7y55N7B0ruleN86',
        'banner' => 'jQuery UI - v1.11.2',
    ],
    'legacy.bootstrap.js' => [
        'path' => '202-js/vendor/legacy/bootstrap-3.3.4.min.js',
        'version' => '3.3.4',
        'sha384' => 'EKxMxgJ+EnnZ5yB3ygGPe1lXaPvlAzKFXU+wW7kO9Bn9ZCgFRrc3wWtCnsDHbemf',
        'banner' => 'Bootstrap v3.3.4',
    ],
    'legacy.select2.js' => [
        'path' => '202-js/vendor/legacy/select2-4.0.1-rc.1.min.js',
        'version' => '4.0.1-rc.1',
        'sha384' => 'qo4GC4ybLCuD4tJwpdLnDt87GE0sx+aMRrzF9dfzCOXUnX70wls8TP96AGy6IfTz',
        'banner' => 'Select2 4.0.1-rc.1',
    ],
    'legacy.tablesorter.js' => [
        'path' => '202-js/vendor/legacy/jquery.tablesorter-2.22.5.min.js',
        'version' => '2.22.5',
        'sha384' => 'pbekISDNEJbos5bxtmt4au1rhUonXLfhNjZp+vMKvE/W8rBPet0WhAq1Oxg/jGqB',
        'banner' => 'version="2.22.5"',
    ],
    'legacy.tablesorter-widgets.js' => [
        'path' => '202-js/vendor/legacy/jquery.tablesorter.widgets-2.22.5.js',
        'version' => '2.22.5',
        'sha384' => '56ggb7HobGz0G9XSydo3YjQqGN5WndQN1zL8WeWiubBLGRcSM9+dzp4RxPtLkboU',
        'banner' => '(v2.22.5)',
    ],
    'legacy.tablesorter-pager.js' => [
        'path' => '202-js/vendor/legacy/jquery.tablesorter.pager-2.22.4.min.js',
        'version' => '2.22.4',
        'sha384' => '8jC1QIQeC20i5DuDYsRbgnzGE4CHp73vGg9d1v8yjICaQKg3SXat47IpsEzgrkKY',
        'banner' => '(v2.22.4)',
    ],
    'legacy.tablesorter-pager.css' => [
        'path' => '202-css/vendor/legacy/jquery.tablesorter.pager-2.22.4.min.css',
        'version' => '2.22.4',
        'sha384' => 'nyMWw145mF172Mg66u5RYhpLPkysWKAoCaCWbGGN3ThN3yAWtk7CvH4H0c94Zscy',
    ],
    'legacy.tablesorter-theme.css' => [
        'path' => '202-css/vendor/legacy/tablesorter-theme.bootstrap-2.22.5.min.css',
        'version' => '2.22.5',
        'sha384' => 'v28LrrX3mEFXYteiYprAxnBOP17OB0r5MzBovmArLg6WECMnHPxiLTDu7mO6uNS2',
    ],
];
