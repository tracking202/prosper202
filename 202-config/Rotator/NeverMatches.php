<?php

declare(strict_types=1);

namespace Prosper202\Rotator;

use Prosper202\Database\Connection;
use Prosper202\Http\PrivacySetting;

/**
 * Why a stored redirector rule sends no visitor anywhere, in words for the
 * person who made it: what CriterionMatcher does with the rule, said before
 * the traffic finds out. One reading for Setup › Redirectors and the API.
 *
 * It says only what it can show: a value outside what its type compares, a
 * check the account cannot make, a rule behind one that takes every
 * visitor. A rule that merely matches nobody today is not its to know.
 */
final class NeverMatches
{
    private function __construct()
    {
    }

    /**
     * What the account has that a rule may need, as the redirects read it.
     *
     * @return array{ipqs_key: bool, privacy_all: bool, isp: bool}
     */
    public static function account(Connection $conn, int $userId): array
    {
        $stmt = $conn->prepareRead('SELECT maxmind_isp FROM 202_users_pref WHERE user_id = ?');
        $conn->bind($stmt, 'i', [$userId]);
        $row = $conn->fetchOne($stmt);

        return [
            'ipqs_key' => FraudScore::accountKey($conn, $userId) !== '',
            'privacy_all' => PrivacySetting::forAccountOrFail($conn, $userId) === 'all',
            'isp' => (string) ($row['maxmind_isp'] ?? '') === '1',
        ];
    }

    /**
     * Why a type's rule matches no visitor on this account whatever its
     * value, for each type that has such a reason.
     *
     * @param array{ipqs_key: bool, privacy_all: bool, isp: bool} $account
     * @return array<string, string>
     */
    public static function types(array $account): array
    {
        $reasons = [];
        if (!$account['ipqs_key']) {
            $reasons['fraud'] = 'No IPQualityScore key is saved (Settings › API Integrations), so no visitor is scored'
                . ' and this rule is skipped for every one of them.';
        } elseif ($account['privacy_all']) {
            $reasons['fraud'] = 'Your privacy setting holds every visitor back, so none is sent to IPQualityScore'
                . ' and this rule is skipped for every one of them.';
        }
        if (!$account['isp']) {
            $reasons['isp'] = 'Without the MaxMind ISP database no visitor has an ISP: "is" matches none of them,'
                . ' and "is not" matches all of them.';
        }

        return $reasons;
    }

    /**
     * Why one stored criterion matches no visitor, or one of its values
     * never does; null when nothing shows it.
     *
     * @param array{ipqs_key: bool, privacy_all: bool, isp: bool} $account
     */
    public static function criterion(string $type, string $statement, ?string $value, array $account): ?string
    {
        $values = Criterion::values($value);
        if (Criterion::matchesEveryone($values)) {
            return null;
        }
        if (!isset(CriterionMatcher::TYPES[$type])) {
            return 'The redirects do not read a "' . $type . '" criterion, so this rule matches no visitor.';
        }
        if ($statement !== 'is' && $statement !== 'is_not') {
            return 'The redirects read "is" and "is not", not "' . $statement . '", so this rule matches no visitor.';
        }
        if ($values === []) {
            return $statement === 'is' ? 'This criterion has no value, so it matches no visitor.' : null;
        }
        $reason = self::types($account)[$type] ?? null;
        if ($reason !== null) {
            return $reason;
        }
        if ($type === 'country') {
            return self::unread(
                $values,
                static fn (string $v): bool => Criterion::countryCode($v) === null,
                'a country is its two-letter code or Name(CC), such as United States(US).'
            );
        }
        if ($type === 'region' || $type === 'city') {
            return self::unread(
                $values,
                static fn (string $v): bool => preg_match('/\([A-Za-z]{2}\)$/D', $v) !== 1,
                'a ' . ($type === 'region' ? 'state or region' : 'city') . ' is written with its country code, such as '
                . ($type === 'region' ? 'Texas(US).' : 'Austin(US).')
            );
        }

        return Criterion::refusal($type, implode(',', $values));
    }

    /**
     * @param list<string> $values
     * @param callable(string): bool $isUnread
     */
    private static function unread(array $values, callable $isUnread, string $form): ?string
    {
        $unread = array_values(array_filter($values, $isUnread));

        return $unread === [] ? null
            : '"' . implode('", "', $unread) . '" never ' . (count($unread) === 1 ? 'matches' : 'match') . ': ' . $form;
    }

    /**
     * Every warning of a redirector's rules, in the order the redirects
     * try them. `criterion` is the criterion's place in its rule, or null
     * when the warning is about the rule: one that comes after an active
     * rule matching every visitor is never reached.
     *
     * @param list<array<string, mixed>> $rules each with status, rule_name and criteria (type, statement, value)
     * @param array{ipqs_key: bool, privacy_all: bool, isp: bool} $account
     * @return list<array{rule: int, criterion: ?int, message: string}>
     */
    public static function rules(array $rules, array $account): array
    {
        $warnings = [];
        $taker = null;
        foreach (array_values($rules) as $i => $rule) {
            $active = (string) ($rule['status'] ?? '') === '1';
            $criteria = array_values((array) ($rule['criteria'] ?? []));
            if ($active && $taker !== null) {
                $warnings[] = ['rule' => $i, 'criterion' => null, 'message' => 'Rule ' . ($taker[0] + 1)
                    . ' ("' . $taker[1] . '") is tried first and matches every visitor,'
                    . ' so this rule is never reached.'];
            }
            $everyone = true;
            foreach ($criteria as $j => $c) {
                $type = (string) ($c['type'] ?? '');
                $statement = (string) ($c['statement'] ?? '');
                $value = isset($c['value']) ? (string) $c['value'] : null;
                $everyone = $everyone && Criterion::matchesEveryone(Criterion::values($value));
                $message = self::criterion($type, $statement, $value, $account);
                if ($message !== null) {
                    $warnings[] = ['rule' => $i, 'criterion' => $j, 'message' => $message];
                }
            }
            if ($active && $everyone && $taker === null) {
                $taker = [$i, (string) ($rule['rule_name'] ?? '')];
            }
        }

        return $warnings;
    }
}
