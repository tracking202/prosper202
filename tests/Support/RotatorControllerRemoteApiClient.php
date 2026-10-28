<?php

declare(strict_types=1);

namespace Tests\Support;

use Api\V3\Controllers\RotatorsController;
use Api\V3\HttpException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\RemoteApiClient;

/**
 * A remote Prosper202 install whose redirector routes are answered by the
 * real RotatorsController on a scratch database, for one account. Only the
 * HTTP exchange (send()) is replaced: the sync engine's requests, and the
 * controller's answers and refusals, are the real ones. Every other list
 * a sync reads is empty.
 *
 * With $ruleSetRoute false it answers PUT /rotators/{id}/rules as an
 * install from before 1.9.79 does, with the router's bare 404.
 */
final class RotatorControllerRemoteApiClient extends RemoteApiClient
{
    /** @var list<string> "METHOD path" of each request */
    public array $requests = [];

    public function __construct(
        private readonly \mysqli $db,
        private readonly int $userId,
        public bool $ruleSetRoute = true
    ) {
        parent::__construct('http://install-' . $userId . '.example', 'key');
    }

    protected function send(string $method, string $url, array $headers, ?string $body): array
    {
        $parts = parse_url($url);
        $path = substr((string) ($parts['path'] ?? ''), strlen('/api/v3/'));
        parse_str((string) ($parts['query'] ?? ''), $query);
        $this->requests[] = "$method $path";
        $payload = $body === null ? [] : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $rotators = new RotatorsController($this->db, $this->userId);
        $segments = explode('/', $path);

        try {
            $answer = match (true) {
                $method === 'GET' && $path === 'rotators' => $rotators->list($query),
                $method === 'GET' && count($segments) === 1 => ['data' => [], 'pagination' => ['total' => 0]],
                $method === 'GET' && count($segments) === 2 => $rotators->get((int) $segments[1]),
                $method === 'POST' && $path === 'rotators' => $rotators->create($payload),
                $method === 'PUT' && count($segments) === 2 => $rotators->update((int) $segments[1], $payload),
                $method === 'POST' && count($segments) === 3 => $rotators->createRule((int) $segments[1], $payload),
                $method === 'PUT' && count($segments) === 3 && $this->ruleSetRoute
                    => $rotators->setRules((int) $segments[1], $payload, false, static function (): void {
                    }),
                $method === 'PUT' && count($segments) === 3 => throw new HttpException('Not found', 404),
                $method === 'DELETE' && count($segments) === 4
                    => $rotators->deleteRule((int) $segments[1], (int) $segments[3]),
                default => throw new \LogicException("No route for $method $path"),
            };
        } catch (ValidationException $e) {
            return [422, (string) json_encode(['message' => $e->getMessage(), 'field_errors' => $e->getFieldErrors()])];
        } catch (HttpException $e) {
            return [$e->getHttpStatus(), (string) json_encode(['message' => $e->getMessage()])];
        }

        return $answer === null ? [204, ''] : [200, (string) json_encode($answer)];
    }
}
