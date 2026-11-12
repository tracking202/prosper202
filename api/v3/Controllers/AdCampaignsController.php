<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\UpstreamException;
use Api\V3\Exception\ValidationException;
use Api\V3\HttpException;
use Api\V3\Support\PayloadKeys;
use Api\V3\Support\QueryInt;
use Prosper202\AdPlatforms\AdConnectionInputException;
use Prosper202\AdPlatforms\AdConnectionStore;
use Prosper202\AdPlatforms\OpenAiAds\AdvertiserAnswer;
use Prosper202\AdPlatforms\OpenAiAds\AdvertiserApi;
use Prosper202\AdPlatforms\OpenAiAds\OpenAiAdsCampaigns;
use Prosper202\Database\Connection;

/**
 * /ad-connections/{id}/campaigns, /ad-groups, /ads and /spend: a ChatGPT Ads
 * connection's campaigns, ad groups and ads read from OpenAI, paused and
 * activated, their tracking template set, event settings attached to a
 * campaign, and spend by campaign. Every write previews with ?dry_run=1.
 * OpenAI's own failures answer 404 (no such object), 429 (its rate limit)
 * or 502 (no answer), with its words.
 */
final class AdCampaignsController
{
    private readonly AdConnectionStore $store;
    private readonly OpenAiAdsCampaigns $campaigns;

    public function __construct(\mysqli $db, private readonly int $userId, ?AdvertiserApi $api = null)
    {
        $this->store = new AdConnectionStore(new Connection($db));
        $this->campaigns = new OpenAiAdsCampaigns($this->store, $api ?? AdvertiserApi::fromEnvironment());
    }

    /** @param array<string, mixed> $params */
    public function list(int $connectionId, string $level, array $params): array
    {
        $parent = ['campaigns' => null, 'ad-groups' => 'campaign_id', 'ads' => 'ad_group_id'][$level] ?? null;
        $accepted = array_values(array_filter(['limit', 'after', $parent]));
        $unknown = array_diff(array_keys($params), $accepted);
        if ($unknown !== []) {
            throw new ValidationException('Unknown parameter', [(string) reset($unknown) => 'Not a parameter of this list. Valid: ' . implode(', ', $accepted)]);
        }
        $limit = QueryInt::param($params, 'limit', OpenAiAdsCampaigns::PAGE_LIMIT, 1, OpenAiAdsCampaigns::MAX_PAGE_LIMIT);
        $text = static fn (?string $name): ?string => $name !== null && is_string($params[$name] ?? null) && $params[$name] !== '' ? $params[$name] : null;
        foreach (array_filter([$parent, 'after']) as $name) {
            if (isset($params[$name]) && !is_string($params[$name])) {
                throw new ValidationException('Invalid parameter', [$name => 'Must be text.']);
            }
        }
        $page = $this->answering(fn (): ?array => $this->campaigns->list($this->userId, $connectionId, $level, $text($parent), $limit, $text('after')))
            ?? throw self::notFound($connectionId);

        return ['data' => $page['data'], 'meta' => ['has_more' => $page['has_more'], 'after' => $page['after']]];
    }

    public function get(int $connectionId, string $level, string $objectId): array
    {
        return ['data' => $this->answering(fn (): ?array => $this->campaigns->get($this->userId, $connectionId, $level, $objectId)) ?? throw self::notFound($connectionId)];
    }

    /** @param array<string, mixed> $payload */
    public function setStatus(int $connectionId, string $level, string $objectId, string $action, array $payload, bool $dryRun): array
    {
        PayloadKeys::refuseUnknown($payload, [], 'a ' . $action);

        return ['data' => $this->answering(fn (): ?array => $this->campaigns->setStatus($this->userId, $connectionId, $level, $objectId, $action, $dryRun)) ?? throw self::notFound($connectionId)];
    }

    /** @param array<string, mixed> $payload */
    public function setTemplate(int $connectionId, string $level, string $objectId, array $payload, bool $dryRun): array
    {
        PayloadKeys::refuseUnknown($payload, ['template'], 'a tracking template');
        $template = $payload['template'] ?? null;
        if ($template !== null && !is_string($template)) {
            throw new ValidationException('Validation failed', ['template' => 'A query string, or leave it out for the ChatGPT Ads template Get Links gives.']);
        }

        return ['data' => $this->answering(fn (): ?array => $this->campaigns->setTemplate($this->userId, $connectionId, $level, $objectId, $template, $dryRun)) ?? throw self::notFound($connectionId)];
    }

    /** @param array<string, mixed> $payload */
    public function attachEventSettings(int $connectionId, string $campaignId, array $payload, bool $dryRun): array
    {
        PayloadKeys::refuseUnknown($payload, ['event_setting_ids'], 'event settings to attach');
        PayloadKeys::refuse(PayloadKeys::valueListErrors(
            $payload,
            'event_setting_ids',
            'event setting ids (ces_…)',
            static fn (mixed $id): ?string => is_string($id) ? null : 'must be an event setting id (ces_…), as text'
        ));
        $ids = $payload['event_setting_ids'] ?? [];
        if (count($ids) > 50) {
            throw new ValidationException('Validation failed', ['event_setting_ids' => 'At most 50 at a time; leave it out for the ones setup made.']);
        }

        return ['data' => $this->answering(fn (): ?array => $this->campaigns->attachEventSettings($this->userId, $connectionId, $campaignId, $ids, $dryRun)) ?? throw self::notFound($connectionId)];
    }

    /** @param array<string, mixed> $params */
    public function spend(int $connectionId, array $params): array
    {
        $unknown = array_diff(array_keys($params), ['since', 'until']);
        if ($unknown !== []) {
            throw new ValidationException('Unknown parameter', [(string) reset($unknown) => 'Not a parameter of spend. Valid: since, until']);
        }
        $day = static function (string $name) use ($params): ?string {
            if (!array_key_exists($name, $params) || $params[$name] === '') {
                return null;
            }
            if (!is_string($params[$name])) {
                throw new ValidationException('Invalid parameter', [$name => 'A date, YYYY-MM-DD.']);
            }

            return $params[$name];
        };

        return ['data' => $this->answering(fn (): ?array => $this->campaigns->spend($this->userId, $connectionId, $day('since'), $day('until'), time())) ?? throw self::notFound($connectionId)];
    }

    /**
     * @template T
     * @param callable(): T $call
     * @return T
     */
    private function answering(callable $call): mixed
    {
        try {
            return $call();
        } catch (AdConnectionInputException $e) {
            throw match ($e->platform) {
                AdvertiserAnswer::NOT_FOUND => new NotFoundException($e->getMessage(), $e),
                AdvertiserAnswer::RATE_LIMITED => new HttpException($e->getMessage(), 429, $e),
                AdvertiserAnswer::UNAVAILABLE => new UpstreamException($e->getMessage(), $e),
                default => new ValidationException($e->getMessage(), [$e->field => $e->getMessage()], $e),
            };
        }
    }

    private static function notFound(int $id): NotFoundException
    {
        return new NotFoundException('Ad connection ' . $id . ' not found; p202 ad-connection list shows yours.');
    }
}
