<?php

declare(strict_types=1);

/**
 * Pinned front-end assets.
 *
 * Every third-party file a page shell loads is listed here with the version
 * it was taken from and, for files served from this install, the SHA-384 of
 * the exact bytes in the repository. tests/Api/V3/AssetManifestTest.php checks
 * each file against this list, so a silently re-minified or swapped file, or a
 * version that drifted from its filename, fails the build instead of shipping;
 * tests/Api/V3/ShellIsolationTest.php checks that the shells load nothing
 * third-party that is not listed here.
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
 * The 'legacy.*' entries belong to the classic shell only — Bootstrap 3, Flat
 * UI Pro and the plugins written against them — and are deleted with it. The
 * first group among them is the files that used to load unversioned from a
 * CloudFront bucket; the second is the files that were always in the
 * repository but loaded by bare path, so their version was nowhere recorded.
 * Entries without a prefix are framework-free and may be loaded by either
 * shell.
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

    // Framework-free; either shell may load them.
    'tablesort.js' => [
        'path' => '202-js/vendor/tablesort-3.0.2.min.js',
        'version' => '3.0.2',
        'sha384' => '3Ild5rUh1H8vi7yOiKeJP7BJBNQv4oAb4rK1zpF2tLJQQtaGo9v+7/KOdcEzKEI/',
        'banner' => 'tablesort v3.0.2',
        'patched' => 'the 3.0.2 release build with its tablesort.number sort appended',
    ],
    'list.js' => [
        'path' => '202-js/vendor/list-1.1.1.min.js',
        'version' => '1.1.1',
        'sha384' => '+4Spd1QvcUfkebwFF4DGa6k0Sh5Vs8a+iI4Pd8+J0T15PyYXRtR0XoVfT3e3GgIH',
    ],
    'list-fuzzysearch.js' => [
        'path' => '202-js/vendor/list.fuzzysearch-0.1.0.min.js',
        'version' => '0.1.0',
        'sha384' => 'my6xRC70guD47vxHJN4fuOHwJT9AgMPToZE6OJmLXFkaNLnV/29J7Co6uxHZdQRn',
    ],

    // Classic shell only. Deleted with it. These used to load from CloudFront.
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

    // Classic shell only. Deleted with it. These were always in the repository.
    // The stylesheets keep their directory because they locate their fonts by
    // the relative ../fonts/ path.
    'legacy.bootstrap.css' => [
        'path' => '202-css/css/bootstrap-3.3.4.min.css',
        'version' => '3.3.4',
        'sha384' => '604wwakM23pEysLJAhja8Lm42IIwYrJ0dEAqzFsj9pJ/P5buiujjywArgPCi8eoz',
        'banner' => 'Bootstrap v3.3.4',
    ],
    'legacy.flat-ui.css' => [
        'path' => '202-css/css/flat-ui-pro-1.3.2.min.css',
        'version' => '1.3.2',
        'sha384' => 'FPnKZBp1dc0MdA/3JH+vlwH2tcY7pe7FNZpm4RJfh+R7sdWi+QF+GfPpdIzAGB9/',
        'banner' => 'Flat UI Pro v1.3.2',
    ],
    'legacy.font-awesome.css' => [
        'path' => '202-css/css/font-awesome-4.5.0.min.css',
        'version' => '4.5.0',
        'sha384' => 'XdYbMnZ/QjLh6iI4ogqCTaIjrFk87ip+ekIjefZch0Y+PvJ8CDYtEs1ipDmPorQ+',
        'banner' => 'Font Awesome 4.5.0',
    ],
    'legacy.tokenfield.css' => [
        'path' => '202-css/css/bootstrap-tokenfield-0.11.9.min.css',
        'version' => '0.11.9',
        'sha384' => 'cu7JuHifKOiMVER1OSMjuBV/vATSBP7dazbyVra6gBV3yOUs4Ptus8PIUUvESwg2',
        'banner' => 'bootstrap-tokenfield',
    ],
    'legacy.tokenfield-typeahead.css' => [
        'path' => '202-css/css/tokenfield-typeahead-0.11.9.min.css',
        'version' => '0.11.9',
        'sha384' => '8rXEF434gAZbQP/KijO9hDqn4wr6/fHnodowVqtR8s51wfrG4gOK64gUDQxLl/PZ',
        'banner' => 'bootstrap-tokenfield',
    ],
    'legacy.select2.css' => [
        'path' => '202-css/css/select2-4.0.1-rc.1.css',
        'version' => '4.0.1-rc.1',
        'sha384' => 'WDKNP0wfn6NjI9hT5RQvmZec+V38aA0uMwoYCY6xsexE1D+j9PO18tmvxdKXfjR7',
        'patched' => 'a locally edited copy of the 4.0.x stylesheet (sizes and heights), not release bytes; no banner',
    ],
    'legacy.fileinput.js' => [
        'path' => '202-js/vendor/legacy/flatui-fileinput-0.2.0.js',
        'version' => '0.2.0',
        'sha384' => 'JerWXvl6WLzWx1ww0znWYLeCWdGOp+G2c0mfJwXbi9026lobbrBSwb9hSywGk/nF',
        'banner' => 'flatui-fileinput v0.2.0',
    ],
    'legacy.radiocheck.js' => [
        'path' => '202-js/vendor/legacy/flatui-radiocheck-0.1.0.js',
        'version' => '0.1.0',
        'sha384' => 'waHL+xOzy2Qn/U/U7KA0u9e6L/J4s/m1iODGSTFoK1dpCC9JiY4qbSNnYwwa9Vhl',
        'banner' => 'flatui-radiocheck v0.1.0',
    ],
    'legacy.jquery-validate.js' => [
        'path' => '202-js/vendor/legacy/jquery.validate-1.11.1.min.js',
        'version' => '1.11.1',
        'sha384' => 'B1miHxuCmAMGc0405Cm/zSXCc38EP5hNOy2bblqF6yiX01Svinm1mWMwJDdNDBGr',
        'banner' => 'jQuery Validation Plugin - v1.11.1',
    ],
    'legacy.tokenfield.js' => [
        'path' => '202-js/vendor/legacy/bootstrap-tokenfield-0.11.9.min.js',
        'version' => '0.11.9',
        'sha384' => 'Vjbr4ITT7q1Bcs4EqrrxFUOZ8RB6Rf3h7ijE7VB80YOOBsVEd31MarCiybjLcMjH',
        'banner' => 'bootstrap-tokenfield 0.11.9',
        'patched' => 'one expression: the width of a tokenfield inside .form-inline is fixed at 400px instead of the computed width',
    ],
    'legacy.typeahead.js' => [
        'path' => '202-js/vendor/legacy/typeahead.bundle-0.10.5.min.js',
        'version' => '0.10.5',
        'sha384' => '9tYTT4dZ4XM/9VE3SRm8qwNMN90aRdg1vIOkVEVUjaO+CDQAcDKFN6VgT10CZJKx',
        'banner' => 'typeahead.js 0.10.5',
    ],
];
