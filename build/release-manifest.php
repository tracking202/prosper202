<?php

/**
 * What the release zip ships.
 *
 * The zip is uploaded, as-is, into the web root of hosts where the user has
 * no terminal (cPanel File Manager, FTP). Everything in it is therefore both
 * deployed and, unless the host says otherwise, reachable over HTTP. It must
 * carry everything the app needs at runtime and nothing that only a developer
 * or CI needs.
 *
 * The rule is fail-closed. Every top-level path in the packaged tree must be
 * named in exactly one of 'ship' or 'exclude'; build/scripts/release-tree.php
 * refuses to package a tree with an unclassified path, and the PR check runs
 * the same classification against the tracked files, so a new top-level file
 * forces a decision in the PR that adds it rather than on release day.
 *
 * Read by build/scripts/ReleaseTree.php (packaging, verification and the PR
 * check) and tests/Release/ReleaseTreeTest.php. Patterns use fnmatch().
 */

declare(strict_types=1);

return [
    'ship' => [
        // The application.
        '.well-known',          // SKAdNetwork / AdAttributionKit postback receivers
        '202-404.php',
        '202-Mobile',
        '202-access-denied.php',
        '202-account',
        '202-appstore',
        '202-charts',
        '202-config',
        '202-config-sample.php',
        '202-cronjobs',
        '202-css',
        '202-img',
        '202-interfaces',
        '202-js',
        '202-license.php',
        '202-login.php',
        '202-lost-pass.php',
        '202-pass-reset.php',
        '202-resources',
        '202-tv',
        'api',
        'api-key-required.php',
        'bin',                  // bin/p202, the PHP CLI
        'cli',
        'favicon.gif',
        'favicon.ico',
        'font-awesome.min.css',
        'health',
        'index.php',
        'robots.txt',
        'tracking202',

        // Built during packaging.
        'vendor',               // composer install --no-dev, from composer.lock
        'go-cli',               // only dist/ ships; see 'keep_only'

        // For the operator. documentation/ is also read at runtime by
        // 202-account/docs.php; docs/ is the API and CLI reference.
        '.claude',              // only the onboarding skill; see 'keep_only'
        'ATTRIBUTION_SETUP.md',
        'LICENSE',
        'README.md',
        'changelogs.txt',
        'composer.json',        // lets a terminal user re-run composer install
        'composer.lock',        // ... against the exact set that shipped
        'docs',
        'documentation',
        'install.sh',           // terminal installer for VPS users
    ],

    'exclude' => [
        // Tests, static analysis and their configuration.
        'tests',
        'phpstan-baseline.neon',
        'phpstan-legacy-stubs.php',
        'phpstan.neon.dist',
        'phpunit.ci.xml',
        'phpunit.xml',
        'playwright.config.js',
        'package.json',
        'scripts',              // lint and pattern-check scripts

        // CI, containers and deployment from source.
        '.github',
        '.gitignore',
        '.dockerignore',
        '.env.example',         // docker compose settings
        'Dockerfile',
        'docker-compose*.yaml',
        'docker-compose*.yml',
        'start.sh',             // docker compose launcher
        'build',                // docker entrypoint, staging configs, this manifest

        // Contributor and agent notes, plans and write-ups.
        'AGENTS.md',
        'CLAUDE.md',
        'RELEASING.md',
        'MOBILE_RESPONSIVE_SUMMARY.md',
        'mysql-modernization-plan.md',
        'mysql-tinybird-evaluation.md',
        'task-plan-*.md',

        // The iOS helper is consumed with Swift Package Manager from the
        // repository, never from a server.
        'sdk',
    ],

    // Directories that ship only in part: everything directly under the key
    // is removed except the listed children. Written as an allowlist so a
    // new file there (a Go source file, another developer skill) stays out.
    'keep_only' => [
        '.claude' => ['skills'],
        // find-cli.sh resolves the bundled go-cli/dist binary, so the
        // onboarding skill works from an unpacked release.
        '.claude/skills' => ['onboard-prosper202'],
        // Pre-built binaries only; the Go source is not needed to run them.
        'go-cli' => ['dist'],
    ],

    // Developer-only paths inside shipped directories.
    'exclude_nested' => [
        '202-config/PHPStan',                     // PHPStan rules; need phpstan/phpstan, a dev dependency
        '202-config/Messaging/mock-server.php',   // local stand-in for the central messaging API
        '202-config/Messaging/MOCK-SERVER.md',
        '202-config/Messaging/CENTRAL-API.md',    // contract for the central server, not the install
    ],

    // Go CLI binaries the zip promises (README, RELEASING.md, and the
    // onboarding skill's find-cli.sh all rely on this layout).
    'go_binaries' => [
        'go-cli/dist/linux-amd64/p202',
        'go-cli/dist/linux-arm64/p202',
        'go-cli/dist/darwin-amd64/p202',
        'go-cli/dist/darwin-arm64/p202',
        'go-cli/dist/windows-amd64/p202.exe',
        'go-cli/dist/windows-arm64/p202.exe',
    ],

    // Names the shipped PHP references that neither the shipped code declares
    // nor the shipped autoloader finds (case-sensitively, as on the Linux hosts
    // that run the zip), each with why that does not break a user. This list
    // exists to stop growing; anything new here means vendor/ is missing
    // something.
    'known_unresolved' => [
        // class-indexes.php is reached only through functions-indexes.php,
        // which connect.php includes after functions-tracking202.php has
        // declared INDEXES, so the file returns before its class is declared.
        'IPRegistry\IPRegistry' => 'dead copy of INDEXES::get_ip_id() in 202-config/class-indexes.php;'
            . ' never a Composer package',
    ],
];
