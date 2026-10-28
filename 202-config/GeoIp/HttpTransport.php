<?php

declare(strict_types=1);

namespace Prosper202\GeoIp;

/** One GET for the GeoIP download, redirects not followed (MaxMindDownload follows them). */
interface HttpTransport
{
    /**
     * A 200's body goes to $sink when one is given, else into the response;
     * any other status's body (at most 4 KB of it) is in the response.
     *
     * @param array{0: string, 1: string}|null $basicAuth user and password, or none
     * @param resource|null $sink
     * @throws RefreshFailed when no answer arrived, the guard refused the URL, or the body passed $maxBytes
     */
    public function get(string $url, ?array $basicAuth, $sink, int $maxBytes): HttpResponse;
}
