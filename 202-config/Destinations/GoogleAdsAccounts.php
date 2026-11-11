<?php

declare(strict_types=1);

namespace Prosper202\Destinations;

use Prosper202\Attribution\WebhookSender;

/**
 * What the connected Google user can send conversions to, read from the
 * Google Ads API with the destination's own credentials, so Setup ›
 * Destinations offers the accounts and conversion actions to pick instead
 * of asking for their IDs:
 *
 * - accounts(): CustomerService.ListAccessibleCustomers, then each of those
 *   accounts' customer_client rows (itself, and for a manager every account
 *   under it). An account reached through a manager carries that manager as
 *   its login customer; one the user reaches directly needs none.
 * - conversionActions(): the account's enabled conversion actions that take
 *   click uploads (type UPLOAD_CLICKS), the only kind GoogleAdsDestination
 *   can send to.
 *
 * Both go through WebhookSender::read(), so the requests are pinned and
 * bounded as every other outbound call. Nothing is stored here: what the
 * operator picks is saved as the destination's ordinary settings.
 *
 * @phpstan-type Account array{customer_id: string, login_customer_id: ?string, name: string, currency: string, via: ?string}
 * @phpstan-type Action array{value: string, id: string, name: string, owner: ?string}
 */
final class GoogleAdsAccounts
{
    /** Accounts the Google user reaches directly that are read; the rest are counted. */
    public const MAX_ROOTS = 20;
    /** Accounts read under one manager, and actions read of one account. */
    public const MAX_ROWS = 500;
    /** Seconds accounts() spends before it stops asking and says so. */
    public const BUDGET = 25.0;
    /** The only conversion action type uploadClickConversions takes. */
    public const UPLOAD_TYPE = 'UPLOAD_CLICKS';

    private const ACCOUNTS_QUERY = 'SELECT customer_client.id, customer_client.descriptive_name, customer_client.currency_code,'
        . ' customer_client.manager, customer_client.level FROM customer_client'
        . " WHERE customer_client.status = 'ENABLED' LIMIT " . (self::MAX_ROWS + 1);
    private const ACTIONS_QUERY = 'SELECT conversion_action.id, conversion_action.name, conversion_action.type,'
        . ' conversion_action.owner_customer FROM conversion_action'
        . " WHERE conversion_action.status = 'ENABLED' LIMIT " . (self::MAX_ROWS + 1);

    private GoogleAdsOAuth $oauth;
    /** @var array<string, string> access tokens minted by this object, by client and grant */
    private array $tokens = [];
    /** @var (callable(): float) */
    private $clock;

    /** @param (callable(): float)|null $clock seconds, for the budget */
    public function __construct(private ?WebhookSender $sender = null, ?GoogleAdsOAuth $oauth = null, ?callable $clock = null)
    {
        $this->oauth = $oauth ?? new GoogleAdsOAuth($sender);
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * The accounts conversions can go to, by name. `error` is why there are
     * none; `notes` say what was left out of a list that has some.
     *
     * @param array<string, mixed> $credentials the destination's, opened (DestinationStore::forSending())
     * @return array{accounts: list<Account>, notes: list<string>, error: ?string}
     */
    public function accounts(#[\SensitiveParameter] array $credentials): array
    {
        $none = ['accounts' => [], 'notes' => [], 'error' => null];
        $token = $this->accessToken($credentials);
        if ($token['error'] !== null) {
            return ['error' => $token['error']] + $none;
        }
        $listed = $this->call('GET', 'customers:listAccessibleCustomers', null, $credentials, $token['token'], null);
        if ($listed['error'] !== null) {
            return ['error' => 'Google Ads did not list your accounts: ' . $listed['error']] + $none;
        }
        $roots = [];
        foreach ((array) ($listed['json']['resourceNames'] ?? []) as $name) {
            if (is_string($name) && preg_match('/\Acustomers\/([0-9]{10})\z/D', $name, $m) === 1) {
                $roots[$m[1]] = $m[1];
            }
        }
        if ($roots === []) {
            return ['error' => 'The Google user you connected has no Google Ads account. Connect again as a user who can open the account in Google Ads.'] + $none;
        }

        $notes = [];
        if (count($roots) > self::MAX_ROOTS) {
            $notes[] = 'The Google user reaches ' . count($roots) . ' accounts directly; the first ' . self::MAX_ROOTS . ' were read.';
            $roots = array_slice($roots, 0, self::MAX_ROOTS, true);
        }
        $started = ($this->clock)();
        $accounts = [];
        $firstError = null;
        foreach ($roots as $root) {
            if (($this->clock)() - $started > self::BUDGET) {
                $notes[] = 'Reading the accounts took too long, so some are not listed. Reload to try again.';
                break;
            }
            $read = $this->search($root, $root, self::ACCOUNTS_QUERY, $credentials, $token['token']);
            if ($read['error'] !== null) {
                // An account the user can no longer open (cancelled, suspended) fails alone.
                $firstError ??= $read['error'];
                continue;
            }
            if (count($read['rows']) > self::MAX_ROWS) {
                $notes[] = 'Manager account ' . self::dashed($root) . ' has more than ' . self::MAX_ROWS . ' accounts; only ' . self::MAX_ROWS . ' are listed.';
            }
            $clients = [];
            foreach (array_slice($read['rows'], 0, self::MAX_ROWS) as $row) {
                $client = is_array($row['customerClient'] ?? null) ? $row['customerClient'] : [];
                $id = self::digits($client['id'] ?? null);
                if ($id !== null) {
                    $clients[$id] = $client + ['name' => is_string($client['descriptiveName'] ?? null) ? trim($client['descriptiveName']) : ''];
                }
            }
            $rootName = $clients[$root]['name'] ?? '';
            foreach ($clients as $id => $client) {
                $id = (string) $id;
                $name = $client['name'];
                if (($client['manager'] ?? false) === true) {
                    continue;
                }
                $direct = $id === $root;
                // Reached directly beats reached through a manager: it needs no login customer.
                if (isset($accounts[$id]) && ($accounts[$id]['login_customer_id'] === null || !$direct)) {
                    continue;
                }
                $accounts[$id] = [
                    'customer_id' => $id,
                    'login_customer_id' => $direct ? null : $root,
                    'name' => $name,
                    'currency' => is_string($client['currencyCode'] ?? null) ? $client['currencyCode'] : '',
                    'via' => $direct ? null : ($rootName !== '' ? $rootName : self::dashed($root)),
                ];
            }
        }
        if ($accounts === []) {
            return [
                'error' => $firstError !== null
                    ? 'Google Ads did not list the accounts: ' . $firstError
                    : 'The Google user you connected reaches only manager accounts with no account under them. Conversions go to an account that runs ads.',
                'notes' => $notes,
            ] + $none;
        }
        usort($accounts, static fn (array $a, array $b): int => [strtolower($a['name']), $a['customer_id']] <=> [strtolower($b['name']), $b['customer_id']]);

        return ['accounts' => $accounts, 'notes' => $notes, 'error' => null];
    }

    /**
     * The account's conversion actions a click conversion can be uploaded
     * to. `others` counts its enabled actions of another kind (a website
     * tag, an app, a call), which cannot take one.
     *
     * @param array<string, mixed> $credentials
     * @return array{actions: list<Action>, others: int, notes: list<string>, error: ?string}
     */
    public function conversionActions(#[\SensitiveParameter] array $credentials, string $customerId, ?string $loginCustomerId): array
    {
        $none = ['actions' => [], 'others' => 0, 'notes' => [], 'error' => null];
        if (preg_match('/\A[0-9]{10}\z/D', $customerId) !== 1 || ($loginCustomerId !== null && preg_match('/\A[0-9]{10}\z/D', $loginCustomerId) !== 1)) {
            return ['error' => 'The account\'s customer ID is not 10 digits.'] + $none;
        }
        $token = $this->accessToken($credentials);
        if ($token['error'] !== null) {
            return ['error' => $token['error']] + $none;
        }
        $read = $this->search($customerId, $loginCustomerId, self::ACTIONS_QUERY, $credentials, $token['token']);
        if ($read['error'] !== null) {
            return ['error' => 'Google Ads did not list the conversion actions of ' . self::dashed($customerId) . ': ' . $read['error']] + $none;
        }
        $notes = [];
        if (count($read['rows']) > self::MAX_ROWS) {
            $notes[] = 'The account has more than ' . self::MAX_ROWS . ' conversion actions; only ' . self::MAX_ROWS . ' were read.';
        }
        $actions = [];
        $others = 0;
        foreach (array_slice($read['rows'], 0, self::MAX_ROWS) as $row) {
            $action = is_array($row['conversionAction'] ?? null) ? $row['conversionAction'] : [];
            $id = is_string($action['id'] ?? null) || is_int($action['id'] ?? null) ? (string) $action['id'] : '';
            if (preg_match('/\A[1-9][0-9]{0,18}\z/D', $id) !== 1) {
                continue;
            }
            if (($action['type'] ?? null) !== self::UPLOAD_TYPE) {
                $others++;
                continue;
            }
            // An action a manager owns is named by its owner (GoogleAdsDestination::actionResource()).
            $owner = is_string($action['ownerCustomer'] ?? null) && preg_match('/\Acustomers\/([0-9]{10})\z/D', $action['ownerCustomer'], $m) === 1 ? $m[1] : null;
            $foreign = $owner !== null && $owner !== $customerId;
            $actions[] = [
                'value' => $foreign ? 'customers/' . $owner . '/conversionActions/' . $id : $id,
                'id' => $id,
                'name' => is_string($action['name'] ?? null) ? trim($action['name']) : '',
                'owner' => $foreign ? $owner : null,
            ];
        }

        usort($actions, static fn (array $a, array $b): int => [strtolower($a['name']), $a['id']] <=> [strtolower($b['name']), $b['id']]);

        return ['actions' => $actions, 'others' => $others, 'notes' => $notes, 'error' => null];
    }

    /** 1234567890 as Google Ads shows it, 123-456-7890. */
    public static function dashed(string $customerId): string
    {
        return preg_match('/\A[0-9]{10}\z/D', $customerId) === 1
            ? substr($customerId, 0, 3) . '-' . substr($customerId, 3, 3) . '-' . substr($customerId, 6)
            : $customerId;
    }

    /**
     * @param array<string, mixed> $credentials
     * @return array{rows: list<array<string, mixed>>, error: ?string}
     */
    private function search(string $customerId, ?string $loginCustomerId, string $query, #[\SensitiveParameter] array $credentials, #[\SensitiveParameter] string $token): array
    {
        $answer = $this->call(
            'POST',
            'customers/' . $customerId . '/googleAds:search',
            json_encode(['query' => $query], JSON_THROW_ON_ERROR),
            $credentials,
            $token,
            $loginCustomerId
        );
        if ($answer['error'] !== null) {
            return ['rows' => [], 'error' => $answer['error']];
        }
        $rows = [];
        foreach ((array) ($answer['json']['results'] ?? []) as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return ['rows' => $rows, 'error' => null];
    }

    /**
     * One Google Ads API request. `error` is null only for a 2xx whose body
     * is a JSON object.
     *
     * @param array<string, mixed> $credentials
     * @return array{json: array<string, mixed>, error: ?string}
     */
    private function call(string $method, string $path, ?string $body, #[\SensitiveParameter] array $credentials, #[\SensitiveParameter] string $token, ?string $loginCustomerId): array
    {
        $secrets = [$credentials['developer_token'] ?? null, $credentials['oauth_client_secret'] ?? null, $credentials[AuthorisedDestination::GRANT] ?? null, $token];
        $failed = static fn (string $why): array => ['json' => [], 'error' => GoogleAdsOAuth::redact($why, $secrets)];
        $headers = ['Authorization: Bearer ' . $token, 'developer-token: ' . (string) ($credentials['developer_token'] ?? ''), 'User-Agent: Prosper202-Destinations/1'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($loginCustomerId !== null) {
            $headers[] = 'login-customer-id: ' . $loginCustomerId;
        }
        $this->sender ??= new WebhookSender();
        $sent = $this->sender->read(
            'https://' . GoogleAdsDestination::HOST . '/' . GoogleAdsDestination::API_VERSION . '/' . $path,
            $method === 'GET' ? null : (string) $body,
            $headers,
            [443]
        );
        if ($sent['refused'] !== null) {
            return $failed('not sent: ' . $sent['refused']);
        }
        if ($sent['strayed'] !== null) {
            return $failed('not sent: the connection went to ' . $sent['strayed'] . ' instead of the checked address ' . $sent['pinned']);
        }
        if ($sent['status'] === 0) {
            return $failed('no answer from Google Ads: ' . ($sent['error'] ?? 'the request failed'));
        }
        if ($sent['status'] < 200 || $sent['status'] >= 300) {
            $text = GoogleAdsDestination::failureText($sent['body']);

            return $failed('Google Ads answered ' . $sent['status'] . ($text !== '' ? ': ' . $text : ''));
        }
        if ($sent['error'] !== null) {
            return $failed('Google Ads\' answer was cut off (' . $sent['error'] . ')');
        }
        try {
            $json = json_decode($sent['body'], true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $json = null;
        }
        // A list is never read from an answer that is not Google's JSON (CLAUDE.md #4).
        if (!is_array($json) || ($json !== [] && array_is_list($json))) {
            return $failed('Google Ads answered ' . $sent['status'] . ' with something that is not its JSON');
        }

        return ['json' => $json, 'error' => null];
    }

    /**
     * @param array<string, mixed> $credentials
     * @return array{token: string, error: ?string}
     */
    private function accessToken(#[\SensitiveParameter] array $credentials): array
    {
        $grant = $credentials[AuthorisedDestination::GRANT] ?? null;
        if (!is_string($grant) || $grant === '') {
            return ['token' => '', 'error' => 'Google Ads is not connected yet; press Connect Google Ads.'];
        }
        $key = hash('sha256', (string) ($credentials['oauth_client_id'] ?? '') . "\0" . $grant);
        if (isset($this->tokens[$key])) {
            return ['token' => $this->tokens[$key], 'error' => null];
        }
        $answer = $this->oauth->token([
            'grant_type' => 'refresh_token',
            'client_id' => (string) ($credentials['oauth_client_id'] ?? ''),
            'client_secret' => (string) ($credentials['oauth_client_secret'] ?? ''),
            'refresh_token' => $grant,
        ]);
        $token = $answer['json']['access_token'] ?? null;
        if ($answer['error'] === null && is_string($token) && $token !== '') {
            return ['token' => $this->tokens[$key] = $token, 'error' => null];
        }
        $error = GoogleAdsOAuth::redact($answer['error'] ?? 'Google\'s token endpoint gave no access token', [$credentials['oauth_client_secret'] ?? null, $grant]);
        $fix = ($answer['json']['error'] ?? '') === 'invalid_grant'
            ? ' The Google authorisation was revoked or expired: connect Google Ads again.'
            : '';

        return ['token' => '', 'error' => rtrim($error, '. ') . '.' . $fix];
    }

    private static function digits(mixed $value): ?string
    {
        $text = is_int($value) ? (string) $value : (is_string($value) ? $value : '');

        return preg_match('/\A[0-9]{10}\z/D', $text) === 1 ? $text : null;
    }
}
