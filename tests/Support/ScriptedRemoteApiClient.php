<?php

declare(strict_types=1);

namespace Tests\Support;

use Api\V3\Support\RemoteApiClient;

/**
 * A remote Prosper202 API whose answers are scripted: only the HTTP exchange
 * (send()) is replaced, so RemoteApiClient::request() turns each status and
 * body into what its caller sees exactly as it does for a real instance.
 */
final class ScriptedRemoteApiClient extends RemoteApiClient
{
    /** @var list<array{string, string}> method and URL of each request */
    public array $requests = [];

    /** @param array<string, array{int, string}> $answers "METHOD url-fragment" => [status, body] */
    public function __construct(private readonly array $answers)
    {
        parent::__construct('http://target.example', 'key');
    }

    protected function send(string $method, string $url, array $headers, ?string $body): array
    {
        $this->requests[] = [$method, $url];
        foreach ($this->answers as $match => $answer) {
            [$m, $fragment] = explode(' ', $match, 2);
            if ($m === $method && str_contains($url, $fragment)) {
                return $answer;
            }
        }

        return [200, '{"data":{}}'];
    }
}
