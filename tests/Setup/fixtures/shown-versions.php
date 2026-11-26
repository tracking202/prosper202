<?php

/**
 * For the page runners: open a page as a browser does before it posts, in a
 * run of its own under the same ShownSettings secret, and answer each
 * settings form's version the page rendered (form name => version).
 *
 * $env is the child run's environment: the runner's own, with its POST
 * emptied and its query set to the page opened. It declares only: a web
 * request for it runs nothing (TestScriptsAreNotServedTest).
 */

declare(strict_types=1);

/**
 * @param array<string, string> $env
 * @return array<string, string>
 */
function p202_runner_shown_versions(string $runner, string $page, array $env): array
{
    $child = proc_open(
        [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', $runner, $page],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $env + getenv()
    );
    if (!is_resource($child)) {
        fwrite(STDERR, "could not open $page before posting\n");
        exit(2);
    }
    $opened = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($child) === 2) {
        fwrite(STDERR, "opening $page before posting failed: $err\n");
        exit(2);
    }
    preg_match_all('/name="p202_shown\[([a-z0-9_]+)\]" value="([0-9a-f]{64})"/', $opened, $found, PREG_SET_ORDER);
    $versions = [];
    foreach ($found as [, $form, $version]) {
        $versions[$form] ??= $version;
    }

    return $versions;
}

/**
 * $post with each version the opened page rendered, unless $post names one.
 *
 * @param array<string, mixed> $post
 * @param array<string, string> $versions
 * @return array<string, mixed>
 */
function p202_runner_post_with_versions(array $post, array $versions): array
{
    foreach ($versions as $form => $version) {
        if (!isset($post['p202_shown'][$form])) {
            $post['p202_shown'][$form] = $version;
        }
    }

    return $post;
}
