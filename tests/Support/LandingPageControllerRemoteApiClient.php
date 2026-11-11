<?php

declare(strict_types=1);

namespace Tests\Support;

use Api\V3\Controllers\LandingPagesController;
use Api\V3\Exception\ValidationException;
use Api\V3\HttpException;
use Api\V3\Support\RemoteApiClient;

/**
 * A remote Prosper202 install whose landing page routes are answered by the
 * real LandingPagesController on a scratch database, for one account. Only
 * the HTTP exchange (send()) is replaced: the sync engine's requests, and
 * the controller's answers and refusals, are the real ones. Every other
 * list a sync reads is empty.
 */
final class LandingPageControllerRemoteApiClient extends RemoteApiClient
{
    /** @var list<string> "METHOD path" of each request */
    public array $requests = [];

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
        parent::__construct('http://install-' . $userId . '.example', 'key');
    }

    protected function send(string $method, string $url, array $headers, ?string $body): array
    {
        $parts = parse_url($url);
        $path = substr((string) ($parts['path'] ?? ''), strlen('/api/v3/'));
        parse_str((string) ($parts['query'] ?? ''), $query);
        $this->requests[] = "$method $path";
        $payload = $body === null ? [] : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $pages = new LandingPagesController($this->db, $this->userId);
        $segments = explode('/', $path);

        try {
            $answer = match (true) {
                $method === 'GET' && $path === 'landing-pages' => $pages->list($query),
                $method === 'GET' && count($segments) === 1 => ['data' => [], 'pagination' => ['total' => 0]],
                $method === 'GET' && $segments[0] === 'landing-pages' && count($segments) === 2 => $pages->get((int) $segments[1]),
                $method === 'POST' && $path === 'landing-pages' => $pages->create($payload),
                $method === 'PUT' && $segments[0] === 'landing-pages' && count($segments) === 2 => $pages->update((int) $segments[1], $payload),
                default => throw new \LogicException("No route for $method $path"),
            };
        } catch (ValidationException $e) {
            return [422, (string) json_encode(['message' => $e->getMessage(), 'field_errors' => $e->getFieldErrors()])];
        } catch (HttpException $e) {
            return [$e->getHttpStatus(), (string) json_encode(['message' => $e->getMessage()])];
        }

        return [200, (string) json_encode($answer)];
    }
}
