<?php

declare(strict_types=1);

namespace Tests\GeoIp;

use Prosper202\GeoIp\HttpResponse;
use Prosper202\GeoIp\HttpTransport;

/** Answers each GET with the next scripted response and records what it was asked. */
final class ScriptedTransport implements HttpTransport
{
    /** @var list<array{0: string, 1: array{0: string, 1: string}|null}> */
    public array $calls = [];
    /** @var list<mixed> */
    public array $sinks = [];
    /** @var list<int> */
    public array $limits = [];

    /** @param list<HttpResponse> $responses */
    public function __construct(private array $responses)
    {
    }

    #[\Override]
    public function get(string $url, ?array $basicAuth, $sink, int $maxBytes): HttpResponse
    {
        $this->calls[] = [$url, $basicAuth];
        $this->limits[] = $maxBytes;
        if ($sink !== null) {
            $this->sinks[] = $sink;
        }
        $next = array_shift($this->responses);
        if ($next === null) {
            throw new \LogicException('no scripted response left for ' . $url);
        }

        return $next;
    }
}
