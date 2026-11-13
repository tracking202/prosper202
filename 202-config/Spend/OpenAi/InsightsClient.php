<?php

declare(strict_types=1);

namespace Prosper202\Spend\OpenAi;

use Api\V3\Support\ServerStateStore;
use Prosper202\AdPlatforms\OpenAiAds\AdvertiserAnswer;
use Prosper202\AdPlatforms\OpenAiAds\AdvertiserApi;

/**
 * GET /ad_account/insights of the OpenAI Advertiser API, one page at a time:
 * one row per ad per hour (aggregation_level=ad, time_granularity=hourly)
 * with its campaign, ad group and ad ids, impressions, clicks and spend.
 * Requests go through AdvertiserApi, as the ad connections' do: its
 * endpoint (P202_OPENAI_ADS_ENDPOINT, loopback only), its per-minute
 * limits in the install's store, and WebhookSender::read() under it.
 */
final class InsightsClient
{
    public const string HOST = AdvertiserApi::HOST;

    /** @var (\Closure(): AdvertiserApi)|null */
    private ?\Closure $build;

    /** The most rows OpenAI answers in one page. */
    public const int PAGE_ROWS = 2000;

    /** The fields each row carries. */
    public const array FIELDS = ['campaign.id', 'ad_group.id', 'ad.id', 'ad.impressions', 'ad.clicks', 'ad.spend'];

    /** @param (\Closure(): AdvertiserApi)|null $build the API, made at the first request; AdvertiserApi::fromEnvironment() when null */
    public function __construct(private ?AdvertiserApi $api = null, ?\Closure $build = null)
    {
        $this->build = $build;
    }

    /** The install's client: its limits counted where every process of the install counts them. */
    public static function forInstall(\mysqli $db): self
    {
        return new self(null, static fn (): AdvertiserApi => AdvertiserApi::fromEnvironment(ServerStateStore::forInstall($db)));
    }

    /**
     * The query of one page: [$start, $end) in unix seconds, after the
     * cursor $after when one is given.
     *
     * @return array<string, string|list<string>>
     */
    public static function query(int $start, int $end, ?string $after): array
    {
        $query = [
            'aggregation_level' => 'ad',
            'time_granularity' => 'hourly',
            'limit' => (string) self::PAGE_ROWS,
            'time_ranges' => [(string) json_encode(['type' => 'unix_range', 'start' => $start, 'end' => $end])],
            'fields' => self::FIELDS,
        ];
        if ($after !== null) {
            $query['after'] = $after;
        }

        return $query;
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
        $json = $this->answer(fn (AdvertiserApi $api): AdvertiserAnswer => $api->get($apiKey, 'ad_account/insights', self::query($start, $end, $after)));
        $isPage = isset($json['data']) && is_array($json['data']) && array_is_list($json['data'])
            && array_key_exists('has_more', $json) && is_bool($json['has_more']);
        if (!$isPage) {
            throw new InsightsFailure('OpenAI answered with something that is not a page of insights');
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

    /**
     * POST /conversions/insights: the goal conversions OpenAI attributes to
     * each of $adIds per day of the ad account's zone, over [$start, $end)
     * (whole days). OpenAI answers at most 2000 rows and refuses more with
     * 413 rather than a partial answer: tooLarge() says so, and the caller
     * asks again for fewer ads.
     *
     * @param list<string> $adIds
     * @return list<array<string, mixed>>
     * @throws InsightsFailure
     */
    public function conversions(#[\SensitiveParameter] string $apiKey, int $start, int $end, array $adIds): array
    {
        $body = [
            'aggregation_level' => 'ad',
            'time_granularity' => 'daily',
            'time_ranges' => [(string) json_encode(['type' => 'unix_range', 'start' => $start, 'end' => $end])],
            'entity_ids' => array_values($adIds),
            'group_by_entity' => true,
            'include_zero_rows' => false,
        ];
        $json = $this->answer(fn (AdvertiserApi $api): AdvertiserAnswer => $api->post($apiKey, 'conversions/insights', $body));
        if (!isset($json['data']) || !is_array($json['data']) || !array_is_list($json['data'])) {
            throw new InsightsFailure('OpenAI answered with something that is not a list of conversion rows');
        }
        foreach ($json['data'] as $i => $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new InsightsFailure("OpenAI's conversion row $i is not an object");
            }
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $json['data'];

        return $rows;
    }

    /**
     * One request, answered as JSON or refused by name: AdvertiserApi's
     * kinds as InsightsFailure's.
     *
     * @param \Closure(AdvertiserApi): AdvertiserAnswer $send
     * @return array<string, mixed>
     * @throws InsightsFailure
     */
    private function answer(\Closure $send): array
    {
        try {
            $this->api ??= ($this->build ?? static fn (): AdvertiserApi => AdvertiserApi::fromEnvironment())();
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            throw new InsightsFailure('not sent: ' . $e->getMessage());
        }
        $answer = $send($this->api);
        if ($answer->isOk()) {
            return $answer->json;
        }
        $status = $answer->status;
        $error = (string) $answer->error;
        [$message, $code] = match (true) {
            $status === 429 => ['OpenAI answered 429: too many requests', InsightsFailure::RATE_LIMITED],
            $answer->kind === AdvertiserAnswer::RATE_LIMITED => [$error, InsightsFailure::RATE_LIMITED],
            $status === 413 => ['OpenAI answered 413: more than 2000 rows', InsightsFailure::TOO_LARGE],
            $status === 401 || $status === 403 => ['OpenAI refused the Advertiser API key (' . $status . '); connect the ad account again with a key that can read insights', InsightsFailure::KEY_REFUSED],
            $answer->kind === AdvertiserAnswer::KEY_REFUSED => [$error, InsightsFailure::KEY_REFUSED],
            default => [$error, InsightsFailure::FAILED],
        };

        throw new InsightsFailure($message, $code, $answer->retryAfter);
    }
}
