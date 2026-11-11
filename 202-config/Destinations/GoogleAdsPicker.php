<?php

declare(strict_types=1);

namespace Prosper202\Destinations;

use Prosper202\Database\Exceptions\QueryException;

/**
 * Picking a connected Google Ads destination's account and conversion
 * action from what Google lists (GoogleAdsAccounts), for Setup ›
 * Destinations, GET /destinations/{id}/google-ads/* and `p202 destination
 * pick`: the lists, and one way a choice is saved as the destination's
 * settings, with the names Google gives them as its labels.
 *
 * @phpstan-import-type Account from GoogleAdsAccounts
 * @phpstan-import-type Action from GoogleAdsAccounts
 */
final class GoogleAdsPicker
{
    /** Names listed in a refusal that asks which one was meant. */
    private const NAMED = 20;

    public function __construct(private readonly DestinationStore $store, private readonly GoogleAdsAccounts $google)
    {
    }

    /**
     * The accounts the destination's Google user can send conversions to.
     * Null when the account has no such destination.
     *
     * @return array{accounts: list<Account>, notes: list<string>}|null
     * @throws DestinationInputException when it is not a connected Google Ads destination, or Google refused
     */
    public function accounts(int $userId, int $destinationId): ?array
    {
        $destination = $this->connected($userId, $destinationId);
        if ($destination === null) {
            return null;
        }
        $listed = $this->google->accounts($destination['credentials']);
        if ($listed['error'] !== null) {
            throw new DestinationInputException('google_ads', $listed['error']);
        }

        return ['accounts' => $listed['accounts'], 'notes' => $listed['notes']];
    }

    /**
     * The conversion actions of an account that take uploaded clicks: the
     * destination's own account, or $customerId (through $loginCustomerId).
     *
     * @return array{customer_id: string, login_customer_id: ?string, actions: list<Action>, others: int, notes: list<string>}|null
     * @throws DestinationInputException
     */
    public function conversionActions(int $userId, int $destinationId, ?string $customerId = null, ?string $loginCustomerId = null): ?array
    {
        $destination = $this->connected($userId, $destinationId);
        if ($destination === null) {
            return null;
        }
        if ($customerId === null) {
            if ($loginCustomerId !== null) {
                throw new DestinationInputException('customer_id', 'login_customer_id is the manager customer_id is reached through; give customer_id with it.');
            }
            $customerId = $destination['settings']['customer_id'] ?? null;
            $loginCustomerId = $destination['settings']['login_customer_id'] ?? null;
            if (!is_string($customerId) || $customerId === '') {
                throw new DestinationInputException('customer_id', 'This destination has no account yet: give customer_id, one of GET /destinations/' . $destinationId . '/google-ads/accounts.');
            }
        }
        $customerId = self::customerId($customerId, 'customer_id');
        $loginCustomerId = $loginCustomerId === null ? null : self::customerId($loginCustomerId, 'login_customer_id');
        $read = $this->google->conversionActions($destination['credentials'], $customerId, is_string($loginCustomerId) ? $loginCustomerId : null);
        if ($read['error'] !== null) {
            throw new DestinationInputException('google_ads', $read['error']);
        }

        return ['customer_id' => $customerId, 'login_customer_id' => is_string($loginCustomerId) ? $loginCustomerId : null,
            'actions' => $read['actions'], 'others' => $read['others'], 'notes' => $read['notes']];
    }

    /**
     * Pick by name or ID, from Google's lists. With no $account the
     * destination keeps the account it has, or takes the only one the
     * Google user reaches; with no $action it keeps its action (for the
     * same account) or takes the account's only one. Anything else is
     * refused naming the choices. Null when the account has no such
     * destination; else the destination as saved.
     *
     * @return array<string, mixed>|null
     * @throws DestinationInputException
     */
    public function pick(int $userId, int $destinationId, ?string $account, ?string $action, int $now): ?array
    {
        $destination = $this->connected($userId, $destinationId);
        if ($destination === null) {
            return null;
        }
        $settings = $destination['settings'];
        $stored = is_string($settings['customer_id'] ?? null) ? $settings['customer_id'] : null;
        $customerName = null;
        if ($account === null && $stored !== null) {
            $customerId = $stored;
            $login = is_string($settings['login_customer_id'] ?? null) ? $settings['login_customer_id'] : null;
            $label = $settings['labels']['customer']['name'] ?? null;
            $customerName = is_string($label) ? $label : null;
        } else {
            $listed = $this->accounts($userId, $destinationId);
            if ($listed === null) {
                return null;
            }
            $chosen = self::choose(
                $listed['accounts'],
                $account,
                static fn (array $a): array => [$a['customer_id'], GoogleAdsAccounts::dashed($a['customer_id'])],
                static fn (array $a): string => ($a['name'] !== '' ? $a['name'] . ' ' : '') . '(' . GoogleAdsAccounts::dashed($a['customer_id']) . ')',
                'account',
                'Google Ads account'
            );
            $customerId = $chosen['customer_id'];
            $login = $chosen['login_customer_id'];
            $customerName = $chosen['name'] !== '' ? $chosen['name'] : null;
        }
        $kept = $customerId === $stored && is_string($settings['conversion_action'] ?? null) ? $settings['conversion_action'] : null;
        if ($action === null && $kept !== null) {
            $actionValue = $kept;
            $label = $settings['labels']['conversion_action']['name'] ?? null;
            $actionName = is_string($label) ? $label : null;
        } else {
            $read = $this->conversionActions($userId, $destinationId, $customerId, $login);
            if ($read === null) {
                return null;
            }
            if ($read['actions'] === []) {
                throw new DestinationInputException('conversion_action', 'Account ' . GoogleAdsAccounts::dashed($customerId)
                    . ' has no enabled conversion action that takes uploaded clicks. In Google Ads: Goals › Conversions › New conversion action › Import › Track conversions from clicks.');
            }
            $chosen = self::choose(
                $read['actions'],
                $action,
                static fn (array $a): array => [$a['value'], $a['id']],
                static fn (array $a): string => ($a['name'] !== '' ? $a['name'] . ' ' : '') . '(' . $a['id'] . ')',
                'conversion_action',
                'conversion action'
            );
            $actionValue = $chosen['value'];
            $actionName = $chosen['name'] !== '' ? $chosen['name'] : null;
        }

        return $this->save($userId, $destinationId, $customerId, $login, $actionValue, $customerName, $actionName, $now);
    }

    /**
     * Save a choice as the destination's settings. Another account than
     * the stored one takes none of its conversion actions with it; a null
     * $action keeps the same account's. Null when the account has no such
     * destination.
     *
     * @return array<string, mixed>|null the destination as saved
     * @throws DestinationInputException
     */
    public function save(int $userId, int $destinationId, string $customerId, ?string $loginCustomerId, ?string $action, ?string $customerName, ?string $actionName, int $now): ?array
    {
        $destination = $this->store->get($userId, $destinationId);
        if ($destination === null) {
            return null;
        }
        if ($destination['type'] !== GoogleAdsDestination::TYPE) {
            throw new DestinationInputException('type', 'Only a Google Ads destination has an account to pick; this one is ' . $destination['type'] . '.');
        }
        $settings = (array) $destination['settings'];
        if (isset($settings['settings_invalid'])) {
            throw new DestinationInputException('settings', 'This destination\'s stored settings cannot be read. Delete it and add it again.');
        }
        $customerId = self::customerId($customerId, 'customer_id');
        $labels = is_array($settings['labels'] ?? null) ? $settings['labels'] : [];
        if (($settings['customer_id'] ?? null) !== $customerId) {
            unset($settings['conversion_action'], $settings['campaign_conversion_actions']);
            $labels = [];
        }
        $settings['customer_id'] = $customerId;
        unset($settings['login_customer_id']);
        if ($loginCustomerId !== null && $loginCustomerId !== '') {
            $settings['login_customer_id'] = $loginCustomerId;
        }
        if ($customerName !== null && trim($customerName) !== '') {
            $labels['customer'] = ['id' => $customerId, 'name' => mb_substr(trim($customerName), 0, 200)];
        }
        if ($action !== null && $action !== '') {
            if (($settings['conversion_action'] ?? null) !== $action) {
                unset($labels['conversion_action']);
            }
            $settings['conversion_action'] = $action;
            if ($actionName !== null && trim($actionName) !== '') {
                $labels['conversion_action'] = ['id' => $action, 'name' => mb_substr(trim($actionName), 0, 200)];
            }
        }
        $settings['labels'] = $labels;

        return $this->store->update($userId, $destinationId, ['settings' => $settings], $now);
    }

    /**
     * The destination opened for a read of Google, or null when there is none.
     *
     * @return array{settings: array<string, mixed>, credentials: array<string, mixed>}|null
     * @throws DestinationInputException
     */
    private function connected(int $userId, int $destinationId): ?array
    {
        try {
            $destination = $this->store->forSending($userId, $destinationId);
        } catch (QueryException | \mysqli_sql_exception $e) {
            // A database error is not the destination's.
            throw $e;
        } catch (\RuntimeException | \JsonException $e) {
            throw new DestinationInputException('credentials', 'This destination\'s credentials cannot be read; set them again.');
        }
        if ($destination === null) {
            return null;
        }
        if ($destination['type'] !== GoogleAdsDestination::TYPE) {
            throw new DestinationInputException('type', 'Only a Google Ads destination has accounts to pick from; this one is ' . $destination['type'] . '.');
        }
        if (!(new GoogleAdsDestination())->authorised($destination['credentials'])) {
            throw new DestinationInputException('status', 'Google Ads is not connected yet: connect it first (Connect Google Ads on Setup › Destinations, or `p202 destination connect ' . $destinationId . '`).');
        }

        return ['settings' => $destination['settings'], 'credentials' => $destination['credentials']];
    }

    /**
     * The one of $rows $asked names: by one of its IDs, else by its name
     * (case ignored), else the only row when nothing was asked.
     *
     * @template T of array<string, mixed>
     * @param list<T> $rows
     * @param callable(T): list<string> $ids
     * @param callable(T): string $shown
     * @return T
     * @throws DestinationInputException
     */
    private static function choose(array $rows, ?string $asked, callable $ids, callable $shown, string $field, string $what): array
    {
        $listed = static function () use ($rows, $shown): string {
            $names = array_map($shown, array_slice($rows, 0, self::NAMED));

            return implode(', ', $names) . (count($rows) > self::NAMED ? ', and ' . (count($rows) - self::NAMED) . ' more' : '');
        };
        if ($asked === null) {
            if (count($rows) === 1) {
                return $rows[0];
            }
            throw new DestinationInputException($field, 'There are ' . count($rows) . ' to choose from; name the ' . $what . ' by its name or ID: ' . $listed() . '.');
        }
        $wanted = trim($asked);
        $byId = array_values(array_filter($rows, static fn (array $r): bool => in_array($wanted, $ids($r), true)));
        if (count($byId) === 1) {
            return $byId[0];
        }
        $byName = array_values(array_filter($rows, static fn (array $r): bool => $wanted !== '' && mb_strtolower((string) $r['name']) === mb_strtolower($wanted)));
        if (count($byName) === 1) {
            return $byName[0];
        }
        if (count($byName) > 1) {
            throw new DestinationInputException($field, count($byName) . ' are named "' . mb_substr($wanted, 0, 100) . '"; give the ID of the ' . $what . ' instead: ' . implode(', ', array_map($shown, array_slice($byName, 0, self::NAMED))) . '.');
        }
        throw new DestinationInputException($field, 'No ' . $what . ' is named or numbered "' . mb_substr($wanted, 0, 100) . '". The choices: ' . $listed() . '.');
    }

    private static function customerId(string $value, string $field): string
    {
        $digits = (string) preg_replace('/[\s\-]/', '', $value);
        if (preg_match('/\A[0-9]{10}\z/D', $digits) !== 1) {
            throw new DestinationInputException($field, $field . ' must be a Google Ads customer ID, 10 digits (123-456-7890).');
        }

        return $digits;
    }
}
