<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../202-config/connect.php';
require_once __DIR__ . '/../202-config/class-dataengine.php';

set_time_limit(0);

try {

    /*
 RollingCurl code Authored by Josh Fraser (www.joshfraser.com)
 */

    // A failed statement ends the job: thrown where it fails (RebuildWindows),
    // printed by the catch below, and nothing after it claimed or marked.
    $windows = new \Prosper202\DataEngine\RebuildWindows(new \Prosper202\Database\Connection($db));

    if ($windows->anyUnfinished()) {
        // The server calls itself: on the install's stored domain (user
        // 1's — never the session's: this URL is public, and every
        // signed-in user sets their own domain, so a session-dependent
        // read let any of them choose the host fetched below) when there
        // is one, else on the listener that served this request, pinned
        // to its address so the call never leaves this machine whatever
        // name the request claimed (SelfCall says why). Read before the
        // window is claimed, so a read that fails cannot strand it.
        $storedDomain = p202StoredTrackingDomain(1);
        $base = \Prosper202\DataEngine\SelfCall::base($storedDomain, $_SERVER, dirname(__DIR__));
        // Every option the calls are made with: nothing here may add to
        // or override them (a redirect followed would leave the pin).
        $selfCallOptions = \Prosper202\DataEngine\SelfCall::curlOptions($storedDomain, $_SERVER);

        // The earliest window no live run holds, claimed for this run:
        // overlapping cron runs take different windows.
        $window = $windows->take();
        if ($window === null) {
            return;
        }

        $urls = [];
        for ($i = $window['from']; $i < $window['to']; $i += 3599) {
            $nextval = $i + 3599;
            $urls[] = $base . '202-cronjobs/dej.php?s=' . $i . '&e=' . $nextval;
        }



        // make sure the rolling window isn't greater than the # of urls
        $rolling_window = 7;
        $rolling_window = (count($urls) < $rolling_window) ? count($urls) : $rolling_window;

        $master = curl_multi_init();
        $curl_arr = [];

        $options = $selfCallOptions;
        $failed = 0;
        $firstFailure = '';
        // A call whose options curl refuses is not sent, and counts as
        // failed, so the window is released for the next run rather than
        // left claimed or marked done.
        $start = static function (string $url) use ($master, $options, &$failed, &$firstFailure): void {
            $ch = curl_init();
            try {
                \Prosper202\Http\CurlOptions::set($ch, [CURLOPT_URL => $url] + $options);
            } catch (\Prosper202\Http\CurlOptionRefused $e) {
                curl_close($ch);
                $failed++;
                if ($firstFailure === '') {
                    $firstFailure = ' The first: ' . $url . ' was not sent: ' . $e->getMessage() . '.';
                }

                return;
            }
            curl_multi_add_handle($master, $ch);
        };

        // start the first batch of requests
        for ($i = 0; $i < $rolling_window; $i++) {
            $start($urls[$i]);
        }

        do {
            while (($execrun = curl_multi_exec($master, $running)) == CURLM_CALL_MULTI_PERFORM);
            if ($execrun != CURLM_OK)
                break;
            // a request was just completed -- find out which one
            while ($done = curl_multi_info_read($master)) {
                $info = curl_getinfo($done['handle']);
                if ($info['http_code'] != 200) {
                    // request failed -- record it so the job is NOT marked processed
                    // and this hour's aggregation is retried on the next run.
                    $failed++;
                    if ($firstFailure === '') {
                        $redirect = (string) ($info['redirect_url'] ?? '');
                        $firstFailure = ' The first: ' . $info['url'] . ' answered HTTP ' . (int) $info['http_code']
                            . ($redirect === '' ? '' : ', redirecting to ' . $redirect
                                . (\Prosper202\DataEngine\SelfCall::followsRedirects($options)
                                    ? ' (beyond the redirects followed)'
                                    : ' (a call pinned to this server follows no redirect; store a tracking domain)'))
                            . '.';
                    }
                }

                // start a new request (it's important to do this before removing the old
                // one) so the rolling window keeps draining, success or failure.
                if ($i < count($urls)) {
                    $start($urls[$i++]);
                }

                // remove and close the completed handle so it does not leak across
                // the long-running cron job.
                curl_multi_remove_handle($master, $done['handle']);
                curl_close($done['handle']);
            }
        } while ($running);

        curl_multi_close($master);

        // A mark or release that throws leaves the window claimed; the
        // next run finds its lock free and takes it again.
        if ($failed === 0) {
            $moved = $windows->complete($window);
        } else {
            // Released, not marked: the next run rebuilds the window.
            error_log("DataEngine Job: {$failed} of " . count($urls) . " aggregation requests failed for window {$window['from']}-{$window['to']}; left unprocessed for retry." . $firstFailure);
            $moved = $windows->release($window);
        }
        if (!$moved) {
            error_log("DataEngine Job: window {$window['from']}-{$window['to']} was no longer"
                . ' this run\'s to ' . ($failed === 0 ? 'mark' : 'release') . '; left as it is.');
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
    error_log("DataEngine Job Error: " . $e->getMessage());
}
