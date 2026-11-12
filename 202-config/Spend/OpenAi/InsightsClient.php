<?php

declare(strict_types=1);

namespace Prosper202\Spend\OpenAi;

use Prosper202\Attribution\WebhookSender;

/**
 * GET /ad_account/insights of the OpenAI Advertiser API, one page at a time:
 * one row per ad per hour (aggregation_level=ad, time_granularity=hourly)
 * with its campaign, ad group and ad ids, impressions, clicks and spend.
 * Requests go through WebhookSender::read() (checked address, pinned
 * connection, https only, no redirects), like every other outbound call.
 */
final class InsightsClient
{
    public const string HOST = 'api.ads.openai.com';

    /** The most rows OpenAI answers in one page. */
    public const int PAGE_ROWS = 2000;

    /** The fields each row carries. */
    public const array FIELDS = ['campaign.id', 'ad_group.id', 'ad.id', 'ad.impressions', 'ad.clicks', 'ad.spend'];

    public function __construct(private ?WebhookSender $sender = null)
    {
    }

    /**
     * The URL of one page: [$start, $end) in unix seconds, after the cursor
     * $after when one is given.
     */
    public static function url(int $start, int $end, ?string $after): string
    {
        $query = [
            'aggregation_level' => 'ad',
            'time_granularity' => 'hourly',
            'limit' => (string) self::PAGE_ROWS,
            'time_ranges[]' => (string) json_encode(['type' => 'unix_range', 'start' => $start, 'end' => $end]),
        ];
        $parts = [];
        foreach ($query as $name => $value) {
            $parts[] = rawurlencode($name) . '=' . rawurlencode($value);
        }
        foreach (self::FIELDS as $field) {
            $parts[] = rawurlencode('fields[]') . '=' . rawurlencode($field);
        }
        if ($after !== null) {
            $parts[] = 'after=' . rawurlencode($after);
        }

        return 'https://' . self::HOST . '/v1/ad_account/insights?' . implode('&', $parts);
    }

    /**
     * One page: its rows, and the cursor of the next page (null when it is
     * the last).
     *
     * @return array{rows: list<array<string, mixed>>, next: ?string}
     * @throws InsightsFailure naming what went wrong; rateLimited() on a 429
     */
    public function page(#[\SensitiveParameter] string $apiKey, int $start, int $end, ?string $after): array
    {
        $this->sender ??= new WebhookSender();
        $sent = $this->sender->read(
            self::url($start, $end, $after),
            null,
            ['Authorization: Bearer ' . $apiKey, 'Accept: application/json', 'User-Agent: Prosper202-SpendSync/1'],
            [443]
        );
        $redact = static fn (string $text): string => $apiKey === '' ? $text : str_replace($apiKey, '[key]', $text);
        if ($sent['refused'] !== null) {
            throw new InsightsFailure('not sent: ' . $redact($sent['refused']));
        }
        if ($sent['strayed'] !== null) {
            throw new InsightsFailure('not sent: the connection went to ' . $sent['strayed'] . ' instead of the checked address ' . $sent['pinned']);
        }
        $status = $sent['status'];
        if ($status === 0) {
            throw new InsightsFailure('no answer from OpenAI: ' . $redact($sent['error'] ?? 'the request failed'));
        }
        if ($status === 429) {
            throw new InsightsFailure('OpenAI answered 429: too many requests', InsightsFailure::RATE_LIMITED);
        }
        if ($status === 401 || $status === 403) {
            throw new InsightsFailure('OpenAI refused the Advertiser API key (' . $status . '); connect the ad account again with a key that can read insights', InsightsFailure::KEY_REFUSED);
        }
        if ($status < 200 || $status >= 300) {
            throw new InsightsFailure('OpenAI answered ' . $status . self::message($redact($sent['body'])));
        }
        if ($sent['error'] !== null) {
            throw new InsightsFailure('OpenAI\'s answer was cut off (' . $sent['error'] . ')');
        }
        try {
            $json = json_decode($sent['body'], true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $json = null;
        }
        if (!is_array($json) || !isset($json['data']) || !is_array($json['data']) || !array_is_list($json['data'])
            || !array_key_exists('has_more', $json) || !is_bool($json['has_more'])) {
            throw new InsightsFailure('OpenAI answered ' . $status . ' with something that is not a page of insights');
        }
        $next = null;
        if ($json['has_more']) {
            $next = $json['last_id'] ?? null;
            if (!is_string($next) || $next === '') {
                throw new InsightsFailure('OpenAI said more rows follow and gave no cursor (last_id)');
            }
        }
        foreach ($json['data'] as $i => $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new InsightsFailure("OpenAI's row $i is not an object");
            }
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $json['data'];

        return ['rows' => $rows, 'next' => $next];
    }

    /** OpenAI's own words for a failure, when its body carries them. */
    private static function message(string $body): string
    {
        $json = json_decode($body, true);
        $message = is_array($json) && is_array($json['error'] ?? null) ? ($json['error']['message'] ?? null) : null;

        return is_string($message) && $message !== '' ? ': ' . mb_substr($message, 0, 300) : '';
    }
}
