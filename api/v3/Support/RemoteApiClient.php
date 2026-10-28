<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\RemoteApiException;
use Api\V3\Exception\SyncException;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookRefused;
use Prosper202\Attribution\WebhookTarget;
use Prosper202\Attribution\WebhookUnresolved;

/**
 * Another Prosper202 install's API, as a sync (SyncEngine) reads and writes
 * it: POST /sync/plan, the sync jobs, `p202 sync`. The URL is a profile's,
 * which whoever queues a sync chooses, and the profile's API key goes with
 * every request, so every request is checked first by
 * WebhookGuard::checkSync() (https, public addresses, http:// and private
 * ones only where P202_WEBHOOK_ALLOW_NETWORKS admits them, link-local and
 * metadata never) and made to the address it approved, pinned, with no
 * proxy (one would resolve the name itself) and no redirect followed (the
 * key would go with it).
 *
 * What a remote can make this server read is bounded: MAX_ANSWER_BYTES of
 * one answer, MAX_PAGES of one list, MAX_SECONDS for the whole sync (one
 * deadline both remotes share, SyncEngine::buildClients()). A cap reached
 * fails the sync naming the cap and the remote; it never reads as a shorter
 * list, which a prune would act on.
 *
 * @phpstan-type Answer array{
 *     status: int, error: string|null, primary_ip: string|null, redirect_url: string|null, body: string, capped: bool
 * }
 */
class RemoteApiClient
{
    /** Records asked for in one page of a list. */
    public const PAGE_ROWS = 500;
    /** Pages of one list a sync reads: 100,000 records of one kind. */
    public const MAX_PAGES = 200;
    /** Bytes of one answer read before the transfer is cut off. */
    public const MAX_ANSWER_BYTES = 8 * 1024 * 1024;
    /** Seconds one sync (both remotes, every request) may take. */
    public const MAX_SECONDS = 900;
    public const CONNECT_TIMEOUT = 10;
    /** Seconds one request may take. */
    public const REQUEST_TIMEOUT = 60;

    private const NOT_FOLLOWED = 'a sync does not follow: the profile\'s API key is sent only to the URL named. '
        . 'Set the profile\'s url to the address it points to.';

    private readonly string $baseUrl;
    private readonly string $origin;
    private readonly float $deadline;
    private ?WebhookGuard $guard;
    /** @var callable(array<int, mixed>): Answer */
    private $transport;

    /**
     * @param float|null $deadline when the sync must be done (microtime); MAX_SECONDS from now by default
     * @param string $side "source" or "target", for messages
     * @param (callable(array<int, mixed>): Answer)|null $transport
     */
    public function __construct(
        string $url,
        #[\SensitiveParameter] private readonly string $apiKey,
        ?float $deadline = null,
        private readonly string $side = '',
        ?WebhookGuard $guard = null,
        ?callable $transport = null,
    ) {
        $this->baseUrl = self::baseUrlFor($url);
        $this->origin = self::originOf($this->baseUrl);
        $this->deadline = $deadline ?? microtime(true) + self::MAX_SECONDS;
        // Built at the first request when not given: an allowlist that does
        // not parse fails the sync, not the construction of the engine.
        $this->guard = $guard;
        $this->transport = $transport ?? [self::class, 'curlTransport'];
    }

    /**
     * What a sync job's save refuses in a profile's url without asking a
     * resolver (WebhookGuard::syncRefusal()): null when nothing, else why.
     */
    public static function profileUrlRefusal(string $url, ?WebhookGuard $guard = null): ?string
    {
        try {
            $guard ??= new WebhookGuard();
        } catch (\UnexpectedValueException $e) {
            return $e->getMessage();
        }

        return $guard->syncRefusal(self::baseUrlFor($url), self::operatorHosts());
    }

    /** The API's base URL for a profile's url: translated (P202_URL_MAP), with /api/v3. */
    private static function baseUrlFor(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new DatabaseException('Remote URL is required');
        }

        $url = rtrim(self::translateUrl($url), '/');
        if (!str_contains($url, '/api/v3')) {
            $url .= '/api/v3';
        }

        return $url;
    }

    /**
     * Translate external URLs to internal Docker service names using P202_URL_MAP.
     * Format: P202_URL_MAP=localhost:8000=web:80,localhost:8001=web2:80
     */
    private static function translateUrl(string $url): string
    {
        $map = getenv('P202_URL_MAP');
        if ($map === false || $map === '') {
            return $url;
        }
        foreach (explode(',', $map) as $entry) {
            $parts = explode('=', trim($entry), 2);
            if (count($parts) !== 2) {
                continue;
            }
            $url = str_replace(trim($parts[0]), trim($parts[1]), $url);
        }
        return $url;
    }

    /**
     * The host names P202_URL_MAP sends a URL to: the operator's own, so
     * the guard takes their spelling (a Docker service name has one label).
     *
     * @return list<string>
     */
    private static function operatorHosts(): array
    {
        $map = getenv('P202_URL_MAP');
        if ($map === false || $map === '') {
            return [];
        }
        $hosts = [];
        foreach (explode(',', $map) as $entry) {
            $parts = explode('=', trim($entry), 2);
            if (count($parts) !== 2) {
                continue;
            }
            $to = (string) preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', trim($parts[1]));
            $host = strtolower(substr($to, 0, strcspn($to, ':/?#')));
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    /**
     * A URL's scheme and host (and port), for a message: never its path or
     * query, nor a user name or password.
     */
    private static function originOf(string $url): string
    {
        $at = strpos($url, '://');
        if ($at === false) {
            return '(not a URL)';
        }
        $rest = substr($url, $at + 3);
        $authority = substr($rest, 0, strcspn($rest, '/?#'));
        $credentials = strrpos($authority, '@');
        if ($credentials !== false) {
            $authority = substr($authority, $credentials + 1);
        }
        $origin = strtolower(substr($url, 0, $at)) . '://' . $authority;
        if (strlen($origin) > 200) {
            $origin = substr($origin, 0, 200) . '...';
        }

        return (string) preg_replace('/[^\x21-\x7E]/', '?', $origin);
    }

    /** @return array<string, mixed> */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query, null, []);
    }

    /** @return array<string, mixed> */
    public function post(string $path, array $body = [], array $extraHeaders = []): array
    {
        return $this->request('POST', $path, [], $body, $extraHeaders);
    }

    /** @return array<string, mixed> */
    public function put(string $path, array $body = [], array $extraHeaders = []): array
    {
        return $this->request('PUT', $path, [], $body, $extraHeaders);
    }

    /** @return array<string, mixed> */
    public function delete(string $path, array $extraHeaders = []): array
    {
        return $this->request('DELETE', $path, [], null, $extraHeaders);
    }

    /**
     * Every record of a list, PAGE_ROWS at a time. An answer that is not a
     * page of records, and a list longer than MAX_PAGES, fail the read: a
     * shorter list than the remote holds would read as records deleted.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllRows(string $endpoint, array $extraQuery = []): array
    {
        $rows = [];
        $what = 'GET ' . ltrim($endpoint, '/') . ' from ' . $this->remote();

        for ($page = 0;; $page++) {
            if ($page >= self::MAX_PAGES) {
                throw SyncException::limit(sprintf(
                    '%s: more than %d pages of %d records (%d), the most a sync reads of one list '
                    . '(RemoteApiClient::MAX_PAGES); the sync stopped rather than sync part of the list.',
                    $what,
                    self::MAX_PAGES,
                    self::PAGE_ROWS,
                    self::MAX_PAGES * self::PAGE_ROWS
                ));
            }
            $offset = $page * self::PAGE_ROWS;
            $resp = $this->get($endpoint, array_merge($extraQuery, [
                'limit' => (string) self::PAGE_ROWS,
                'offset' => (string) $offset,
            ]));

            $list = $resp['data'] ?? null;
            $at = $what . ' (offset ' . $offset . ')';
            if (!is_array($list) || !array_is_list($list)) {
                throw SyncException::remoteFailed($at . ' answered without a list of records.');
            }
            if (count($list) > self::PAGE_ROWS) {
                throw SyncException::remoteFailed($at . ' answered ' . count($list) . ' records to a request for at '
                    . 'most ' . self::PAGE_ROWS . '.');
            }
            foreach ($list as $row) {
                if (!is_array($row)) {
                    throw SyncException::remoteFailed($at . ' answered a record that is not an object.');
                }
                $rows[] = $row;
            }

            $total = $resp['pagination']['total'] ?? null;
            if (is_string($total) && preg_match('/^(0|[1-9][0-9]{0,17})$/D', $total) === 1) {
                $total = (int) $total;
            }
            if ($total !== null && !(is_int($total) && $total >= 0)) {
                throw SyncException::remoteFailed($at . ' answered a pagination total that is not a whole number.');
            }
            if (count($list) < self::PAGE_ROWS) {
                break; // a short page: no more records, whatever total says
            }
            if ($total !== null && $offset + self::PAGE_ROWS >= $total) {
                break;
            }
        }

        return $rows;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /** "the sync target https://host:port", or the origin alone. */
    private function remote(): string
    {
        return ($this->side !== '' ? 'the sync ' . $this->side . ' ' : '') . $this->origin;
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, array $query, ?array $body, array $extraHeaders): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: prosper202-server-sync/1.0',
        ];
        if ($this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }
        foreach ($extraHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $json = null;
        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new DatabaseException('Failed to encode remote request body');
            }
        }

        $method = strtoupper($method);
        [$status, $responseBody] = $this->send($method, $url, $headers, $json);

        $decoded = json_decode($responseBody, true);

        // An error status is the remote's answer, kept with its status and
        // message (RemoteApiException): a 409 conflict and a 500 outage
        // must not both read as "Internal server error" to the sync job.
        if ($status >= 400) {
            $message = is_array($decoded) && is_scalar($decoded['message'] ?? null)
                ? (string) $decoded['message']
                : 'Remote API error ' . $status;
            $fieldErrors = is_array($decoded['field_errors'] ?? null) ? $decoded['field_errors'] : [];
            throw new RemoteApiException($status, $message, $fieldErrors, $method, ltrim($path, '/'));
        }

        $what = $method . ' ' . ltrim($path, '/') . ' from ' . $this->remote();
        // Redirects are not followed: the key would go with them.
        if ($status >= 300) {
            throw SyncException::urlRefused($what . ' answered ' . $status . ', a redirect, which '
                . self::NOT_FOLLOWED);
        }
        // A proxy or maintenance page served as a success must not read as
        // an empty dataset: callers diff and prune against these results.
        if ($status < 200) {
            throw SyncException::remoteFailed($what . ' answered status ' . $status . '.');
        }

        if (trim($responseBody) === '') {
            return []; // 204-style empty success body
        }
        if (!is_array($decoded)) {
            throw SyncException::remoteFailed($what . ' answered ' . $status . ' with a body that is not a JSON '
                . 'object.');
        }

        return $decoded;
    }

    /**
     * One HTTP exchange: the status and the body as received. What they mean
     * is request()'s to decide; this is the transport, and the guard: the
     * URL checked and pinned, the time and the answer bounded.
     *
     * @param list<string> $headers
     * @return array{int, string}
     */
    protected function send(string $method, string $url, array $headers, ?string $body): array
    {
        $path = substr($url, strlen($this->baseUrl) + 1);
        $what = $method . ' ' . substr($path, 0, strcspn($path, '?')) . ' from ' . $this->remote();
        if (!str_starts_with($url, $this->baseUrl . '/')) {
            throw SyncException::urlRefused($what . ': the request is not under the profile\'s URL.');
        }
        $left = (int) floor($this->deadline - microtime(true));
        if ($left < 1) {
            throw $this->outOfTime($what);
        }

        try {
            $this->guard ??= new WebhookGuard();
            $target = $this->guard->checkSync($this->baseUrl, self::operatorHosts());
        } catch (WebhookUnresolved $e) {
            throw SyncException::urlUnresolved('Not sent to ' . $this->remote() . ': ' . $e->getMessage(), $e);
        } catch (WebhookRefused $e) {
            throw SyncException::urlRefused('Not sent to ' . $this->remote() . ': ' . $e->getMessage(), $e);
        } catch (\UnexpectedValueException $e) {
            throw SyncException::urlRefused('Not sent to ' . $this->remote() . ': ' . $e->getMessage(), $e);
        }

        $answer = ($this->transport)(self::curlOptions($target, $method, $url, $headers, $body, $left));

        $connected = $answer['primary_ip'];
        if ($connected !== null && $connected !== '' && !self::sameAddress($connected, $target->pinned())) {
            throw SyncException::urlRefused($what . ': the connection went to ' . $connected . ' instead of the '
                . 'checked address ' . $target->pinned() . '; the pin did not hold, so nothing it answered is read.');
        }
        if ($answer['capped']) {
            throw SyncException::limit($what . ': the answer is larger than ' . self::MAX_ANSWER_BYTES . ' bytes, '
                . 'the most a sync reads of one (RemoteApiClient::MAX_ANSWER_BYTES); the sync stopped rather than '
                . 'read part of it.');
        }
        if ($answer['status'] === 0) {
            if (microtime(true) >= $this->deadline) {
                throw $this->outOfTime($what);
            }
            throw SyncException::remoteFailed($what . ': no answer (' . ($answer['error'] ?? 'unknown error') . ').');
        }
        if ($answer['status'] >= 300 && $answer['status'] < 400) {
            $location = $answer['redirect_url'];
            throw SyncException::urlRefused($what . ' answered ' . $answer['status'] . ', a redirect'
                . ($location !== null ? ' to ' . self::originOf($location) : '') . ', which ' . self::NOT_FOLLOWED);
        }

        return [$answer['status'], $answer['body']];
    }

    private function outOfTime(string $what): SyncException
    {
        return SyncException::limit('The sync ran out of time at ' . $what . ': a sync may take ' . self::MAX_SECONDS
            . ' seconds in all (RemoteApiClient::MAX_SECONDS).');
    }

    /**
     * Every curl option one request uses, from the checked target. Pure, so
     * a test can read exactly what the connection is allowed to do.
     *
     * @param list<string> $headers
     * @return array<int, mixed>
     */
    public static function curlOptions(
        WebhookTarget $target,
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeout = self::REQUEST_TIMEOUT
    ): array {
        $protocol = str_starts_with(strtolower($target->url), 'https://') ? CURLPROTO_HTTPS : CURLPROTO_HTTP;
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PROTOCOLS => $protocol,
            CURLOPT_REDIR_PROTOCOLS => $protocol,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => max(1, min(self::CONNECT_TIMEOUT, $timeout)),
            CURLOPT_TIMEOUT => max(1, min(self::REQUEST_TIMEOUT, $timeout)),
            CURLOPT_NOSIGNAL => true,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        $resolve = $target->resolveEntry();
        if ($resolve !== null) {
            $options[CURLOPT_RESOLVE] = [$resolve];
        }

        return $options;
    }

    /**
     * The real transfer: the status, the body (at most MAX_ANSWER_BYTES;
     * capped says it was cut off there), where curl connected and where a
     * redirect points.
     *
     * @param array<int, mixed> $options
     * @return Answer
     */
    public static function curlTransport(array $options): array
    {
        $ch = curl_init();
        if ($ch === false) {
            return self::noAnswer('curl could not be initialised');
        }
        $body = '';
        $capped = false;
        $options[CURLOPT_RETURNTRANSFER] = false;
        $options[CURLOPT_WRITEFUNCTION] = static function ($handle, string $chunk) use (&$body, &$capped): int {
            if (strlen($body) + strlen($chunk) > self::MAX_ANSWER_BYTES) {
                $capped = true;

                return 0;
            }
            $body .= $chunk;

            return strlen($chunk);
        };
        if (!curl_setopt_array($ch, $options)) {
            $error = curl_error($ch);
            curl_close($ch);

            return self::noAnswer('curl refused an option' . ($error !== '' ? ': ' . $error : ''));
        }
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $primary = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $redirect = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $error = $ok === false ? curl_error($ch) : null;
        curl_close($ch);

        return [
            'status' => $ok === false && !$capped ? 0 : $status,
            'error' => $error,
            'primary_ip' => is_string($primary) && $primary !== '' ? $primary : null,
            'redirect_url' => is_string($redirect) && $redirect !== '' ? $redirect : null,
            'body' => $body,
            'capped' => $capped,
        ];
    }

    /** @return Answer */
    private static function noAnswer(string $error): array
    {
        return [
            'status' => 0, 'error' => $error, 'primary_ip' => null, 'redirect_url' => null,
            'body' => '', 'capped' => false,
        ];
    }

    private static function sameAddress(string $a, string $b): bool
    {
        $x = @inet_pton($a);
        $y = @inet_pton($b);

        return $x !== false && $y !== false && $x === $y;
    }
}
