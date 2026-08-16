<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use InvalidArgumentException;

/**
 * Describes a webhook callback that should be notified once an export completes.
 */
final readonly class ExportWebhook
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $url,
        public ?string $secret,
        public array $headers
    ) {
        if ($this->url === '') {
            throw new InvalidArgumentException('Webhook URL cannot be empty.');
        }

        // SSRF guard: this URL is POSTed to by 202-cronjobs/attribution-export.php
        // on behalf of a user, so an unvalidated value turns the install into a
        // blind request oracle against its own (or the host's) internal network.
        try {
            \Prosper202\Validation\OutboundUrlGuard::assertAllowed($this->url, 'Webhook URL');
        } catch (\RuntimeException $e) {
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        }

        foreach ($this->headers as $key => $value) {
            if (!is_string($key) || $key === '' || !is_string($value)) {
                throw new InvalidArgumentException('Webhook headers must be an associative array of strings.');
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $url = trim((string) ($data['url'] ?? ''));
        if ($url === '') {
            throw new InvalidArgumentException('Webhook URL is required when webhook settings are provided.');
        }

        $secret = isset($data['secret']) && $data['secret'] !== '' ? (string) $data['secret'] : null;
        $headers = [];
        if (isset($data['headers']) && is_array($data['headers'])) {
            foreach ($data['headers'] as $key => $value) {
                $key = (string) $key;
                if ($key === '') {
                    continue;
                }
                $headers[$key] = (string) $value;
            }
        }

        return new self($url, $secret, $headers);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'secret' => $this->secret,
            'headers' => $this->headers,
        ];
    }
}
