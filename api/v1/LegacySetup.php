<?php

declare(strict_types=1);

namespace Api\V1;

use Api\V3\Auth;
use Api\V3\AuthException;
use Api\V3\Controller;
use Api\V3\Controllers\AffNetworksController;
use Api\V3\Controllers\CampaignsController;
use Api\V3\Controllers\LandingPagesController;
use Api\V3\Controllers\PpcAccountPixelsController;
use Api\V3\Controllers\PpcAccountsController;
use Api\V3\Controllers\PpcNetworksController;
use Api\V3\Controllers\RotatorsController;
use Api\V3\Controllers\TextAdsController;
use Api\V3\Controllers\TrackersController;
use Api\V3\Exception\NotFoundException;

/**
 * The paid 1.9.56 setup reads, GET /api/v1/{resource}/?apikey=…, answered
 * from the v3 controllers with the old wrapper key and string values. The
 * key is checked as v3 checks it: its user active, its scope, and the role's
 * Setup permission; every record is the key's own account's.
 */
final class LegacySetup
{
    /** resource => [wrapper key, v3 scope areas read] */
    public const RESOURCES = [
        'ads'            => ['ads', ['text-ads']],
        'landingPages'   => ['landing_pages', ['landing-pages']],
        'trafficSources' => ['traffic_sources', ['ppc-networks', 'ppc-accounts']],
        'redirectors'    => ['smart_redirectors', ['rotators']],
        'trackers'       => ['trackers', ['trackers']],
        'campaigns'      => ['campaigns', ['campaigns']],
        'categories'     => ['categories', ['aff-networks']],
        'version'        => ['version', []],
    ];

    private const PAGE = 500;

    /** v3's optimistic-concurrency fields, which the old answers never had. */
    private const V3_ONLY = ['version', 'etag'];

    /**
     * @param array<string, mixed> $query
     * @return array{0: int, 1: array<string, mixed>} HTTP status and body
     */
    public static function answer(?\mysqli $db, string $resource, string $method, array $query): array
    {
        if (!isset(self::RESOURCES[$resource])) {
            throw new \LogicException("Unknown legacy setup resource $resource");
        }
        $wrapper = self::RESOURCES[$resource][0];
        if ($method !== 'GET') {
            return self::error($wrapper, 405, 'Not allowed request method');
        }
        if ($db === null) {
            return self::error($wrapper, 503, 'Database connection is unavailable');
        }
        $key = $query['apikey'] ?? '';
        if (!is_string($key) || trim($key) === '') {
            return self::error($wrapper, 401, 'Unauthorized');
        }

        try {
            $auth = Auth::fromRequest(['authorization' => 'Bearer ' . $key], $db, 'v1');
            if ($resource === 'version') {
                require_once dirname(__DIR__, 2) . '/202-config/version.php';
                return [200, ['version' => \PROSPER202_VERSION]];
            }
            foreach (self::RESOURCES[$resource][1] as $area) {
                $auth->requireScope($area . ':read');
            }
            $auth->requirePermission($db, 'access_to_setup_section');
        } catch (AuthException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() < 500 ? (int) $e->getCode() : 500;
            if ($status === 500) {
                return self::failure($db, $wrapper, $e);
            }
            return self::error($wrapper, $status, $e->getMessage());
        } catch (\Throwable $e) {
            return self::failure($db, $wrapper, $e);
        }

        try {
            $rows = self::rows($db, $auth->userId(), $resource);
        } catch (\Throwable $e) {
            return self::failure($db, $wrapper, $e);
        }

        // The old endpoints answered null, not [], for an account with none.
        return [200, [$wrapper => $rows === [] ? null : self::legacyValues($rows)]];
    }

    /** @return list<array<string, mixed>> */
    private static function rows(\mysqli $db, int $userId, string $resource): array
    {
        return match ($resource) {
            'ads' => self::all(new TextAdsController($db, $userId), 'text_ad_deleted'),
            'campaigns' => self::all(new CampaignsController($db, $userId), 'aff_campaign_deleted'),
            'categories' => self::all(new AffNetworksController($db, $userId), 'aff_network_deleted'),
            'trackers' => self::all(new TrackersController($db, $userId)),
            'landingPages' => array_map(
                // Bot202's pixel assistant is not part of 1.9.80; its settings are not read.
                static fn (array $page): array => $page + ['bot202_fb_pixel_assistant' => []],
                self::all(new LandingPagesController($db, $userId), 'landing_page_deleted')
            ),
            'trafficSources' => self::trafficSources($db, $userId),
            'redirectors' => self::redirectors($db, $userId),
            default => throw new \LogicException("No rows for $resource"),
        };
    }

    /**
     * Every row a v3 list answers, page by page. $deletedColumn, which v3
     * leaves out, is 0 on each: a list answers live records only.
     *
     * @return list<array<string, mixed>>
     */
    private static function all(Controller|RotatorsController $controller, ?string $deletedColumn = null): array
    {
        $rows = [];
        $offset = 0;
        do {
            $page = $controller->list(['limit' => self::PAGE, 'offset' => $offset]);
            foreach ($page['data'] as $row) {
                $row = array_diff_key($row, array_flip(self::V3_ONLY));
                if ($deletedColumn !== null) {
                    $row[$deletedColumn] = 0;
                }
                $rows[] = $row;
            }
            $offset += self::PAGE;
        } while (count($page['data']) === self::PAGE && $offset < (int) $page['pagination']['total']);

        return $rows;
    }

    /** @return list<array<string, mixed>> networks, each with ppc_accounts, each with its pixels */
    private static function trafficSources(\mysqli $db, int $userId): array
    {
        $pixels = new PpcAccountPixelsController($db, $userId);
        $accountsByNetwork = [];
        foreach (self::all(new PpcAccountsController($db, $userId), 'ppc_account_deleted') as $account) {
            try {
                $accountPixels = $pixels->list((int) $account['ppc_account_id'])['data'];
            } catch (NotFoundException) {
                // Removed since the list, or under a source that is not this account's (never nested below).
                continue;
            }
            $account['traffic_source_account_pixels'] = $accountPixels;
            $accountsByNetwork[(int) $account['ppc_network_id']][] = $account;
        }

        $networks = [];
        foreach (self::all(new PpcNetworksController($db, $userId), 'ppc_network_deleted') as $network) {
            $network['ppc_accounts'] = $accountsByNetwork[(int) $network['ppc_network_id']] ?? [];
            $networks[] = $network;
        }

        return $networks;
    }

    /** @return list<array<string, mixed>> rotators, each with redirector_rules, each with its criteria and redirects */
    private static function redirectors(\mysqli $db, int $userId): array
    {
        $controller = new RotatorsController($db, $userId);
        $out = [];
        foreach (self::all($controller) as $listed) {
            try {
                $rotator = $controller->get((int) $listed['id'])['data'];
            } catch (NotFoundException) {
                // Removed between the list and this read.
                continue;
            }
            $rules = [];
            foreach ($rotator['rules'] as $rule) {
                $rule['redirector_criteria'] = $rule['criteria'];
                $rule['redirector_redirects'] = $rule['redirects'];
                unset($rule['criteria'], $rule['redirects']);
                $rules[] = $rule;
            }
            unset($rotator['rules']);
            $rotator['redirector_rules'] = $rules;
            $out[] = $rotator;
        }

        return $out;
    }

    /**
     * Values as the old endpoints sent them: mysqli's strings, run through
     * utf8_encode(), which made a NULL an empty string.
     */
    public static function legacyValues(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([self::class, 'legacyValues'], $value);
        }
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_float($value)) {
            // (string) 0.00001 is "1.0E-5"; a DECIMAL column never had an exponent.
            $fixed = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');

            return $fixed === '-0' ? '0' : $fixed;
        }

        return is_scalar($value) ? (string) $value : $value;
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private static function error(string $wrapper, int $status, string $message): array
    {
        return [$status, [$wrapper => ['msg' => $message, 'error' => true, 'status' => $status]]];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private static function failure(\mysqli $db, string $wrapper, \Throwable $e): array
    {
        error_log(p202UncaughtLogLine('Legacy setup API answered 500', $e));
        $pending = null;
        try {
            require_once dirname(__DIR__, 2) . '/202-config/version.php';
            $pending = \Prosper202\Database\SchemaVersion::pendingUpgrade($db, \PROSPER202_VERSION);
        } catch (\Throwable) {
            // The 500 stands.
        }

        return $pending !== null
            ? self::error($wrapper, 503, $pending)
            : self::error($wrapper, 500, 'Internal server error');
    }
}
