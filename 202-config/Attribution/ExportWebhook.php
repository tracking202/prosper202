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

        // The SSRF guard deliberately does NOT run here. This constructor is
        // also the row-hydration path (ExportJob::fromDatabaseRow), and
        // findPending() array_maps every pending row through it: one stored
        // http:// or non-resolving webhook_url would throw out of the cron's
        // very first call and strand EVERY pending export, including jobs with
        // no webhook at all, on every tick. listRecentForModel() would 500 the
        // export listing for the same reason, and a transient DNS failure did
        // both. The guard runs where it can fail one request instead of the
        // batch -- at each write boundary:
        //   - AttributionService::scheduleSnapshotExport() (api/v2)
        //   - AttributionController::scheduleExport()      (api/v3)
        // and again at delivery in 202-cronjobs/attribution-export.php, which
        // has to re-check anyway because DNS can change in between.

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
