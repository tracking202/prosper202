<?php

declare(strict_types=1);

require_once __DIR__ . '/Analytics.class.php';

/**
 * Product analytics for the v3 API, which the CLI, `p202 mcp` and the hosted
 * AI connector all go through: which kind of client a user works with (once
 * a day per client), and the setup milestones a create through the API
 * reaches, the same names the web pages record. Analytics tier, so nothing is
 * recorded without consent. Never carries a request body, a name, a URL or an
 * address; never throws into the request.
 */
final class ApiUsage
{
    public const CLIENT_CLI = 'cli';                    // the Go CLI and `p202 mcp`
    public const CLIENT_AI_CONNECTOR = 'ai_connector';  // a hosted AI app connected through AiConnect
    public const CLIENT_API = 'api';                    // any other API key

    /** Successful writes that are setup milestones: [method, path pattern, event]. */
    private const MILESTONES = [
        ['POST', '#^/campaigns$#', 'aff_campaign_added'],
        ['POST', '#^/aff-networks$#', 'aff_network_added'],
        ['POST', '#^/ppc-networks$#', 'traffic_source_added'],
        ['POST', '#^/trackers$#', 'tracker_created'],
        ['POST', '#^/rotators$#', 'rotator_created'],
        ['POST', '#^/landing-pages$#', 'landing_page_added'],
        ['POST', '#^/text-ads$#', 'text_ad_added'],
        ['POST', '#^/goals$#', 'goal_created'],
        ['POST', '#^/(attribution/)?apps$#', 'mobile_app_registered'],
        ['POST', '#^/sync/jobs$#', 'sync_job_created'],
        ['PUT', '#^/users/\d+/click-filter$#', 'click_filter_changed'],
        ['POST', '#^/users/\d+/click-filter/entries$#', 'click_filter_changed'],
        ['PUT', '#^/users/\d+/click-filter/entries/\d+$#', 'click_filter_changed'],
    ];

    /** The milestone a successful request is, or null. */
    public static function milestoneFor(string $method, string $path): ?string
    {
        $method = strtoupper($method);
        $path = '/' . trim($path, '/');
        foreach (self::MILESTONES as [$m, $pattern, $event]) {
            if ($m === $method && preg_match($pattern, $path) === 1) {
                return $event;
            }
        }
        return null;
    }

    /** The client kind from what the request already established. */
    public static function clientKind(bool $aiConnection, string $userAgent): string
    {
        if ($aiConnection) {
            return self::CLIENT_AI_CONNECTOR;
        }
        if (str_starts_with($userAgent, 'p202-cli/')) {
            return self::CLIENT_CLI;
        }
        return self::CLIENT_API;
    }

    /**
     * Record what a finished request means: api_used once per day per
     * client, and its milestone when it was a real (not dry-run, not staged)
     * successful write.
     */
    /**
     * @param callable(): string $clientKind read only for a consented user (it may query)
     */
    public static function record(mysqli $db, int $userId, callable $clientKind, string $method, string $path, int $status, bool $preview): void
    {
        if ($userId <= 0 || $status < 200 || $status >= 300) {
            return;
        }
        try {
            if (!ConsentPolicy::analyticsAllowed($db, $userId)) {
                return;
            }
            $client = $clientKind();
            $service = new MessagingService($db, $userId, []);
            $meta = ['client' => $client];
            if (!$service->hasEventSince('api_used', date('Y-m-d 00:00:00'), $meta)) {
                $service->recordEvent('api_used', $meta, 'analytics');
            }
            $milestone = $preview ? null : self::milestoneFor($method, $path);
            if ($milestone !== null) {
                $service->recordEvent($milestone, ['via' => $client], 'analytics');
            }
        } catch (\Throwable $e) {
            error_log('[Analytics] API usage not recorded: ' . $e->getMessage());
        }
    }
}
