<?php

declare(strict_types=1);

namespace Prosper202\Click\Filter;

use Prosper202\Click\SignedInFromAddress;
use Prosper202\Click\StoredAddressIds;
use Prosper202\Database\Connection;
use Prosper202\Http\StoredVisitorIp;

/**
 * "Would a click from this address be filtered, and by which rule?" for
 * Personal settings and POST /users/{id}/click-filter/test: every check
 * asked of the address, read only, and the decision ClickFilterDecision
 * makes of them, the click path's own rule.
 *
 * The sign-in check is asked of the account's own sign-in address only,
 * where a click is filtered from any user's: the test is no way to learn
 * where other users of the install signed in from.
 *
 * The repeat check is asked of what the account's clicks have recorded: an
 * address with a click in the window would be filtered as a repeat visit
 * now; one without is counted, and its click is what would start the
 * window. Under privacy a click records the masked address (/24, /48), so
 * both forms are asked. Bot detection reads the visitor's browser, not the
 * address, and is not part of this.
 */
final class ClickFilterTester
{
    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * @param string $packed the address (ClickFilterRules::testAddress())
     * @return array<string, mixed>
     */
    public function test(int $userId, string $packed): array
    {
        $store = new MysqlClickFilterStore($this->conn);
        $settings = $store->settings($userId);
        $entry = $store->match($userId, $packed);
        $address = (string) inet_ntop($packed);

        // Your own sign-in only: the click filter asks every user's, and the
        // test must not say where another user of the install signed in from.
        $signedIn = SignedInFromAddress::byUser($this->conn, $address, $userId);
        $crawler = CrawlerRanges::contains($address);
        $seen = $this->recentlySeen($userId, $address, $settings->repeatWithin());
        $repeat = $seen !== null;

        $verdict = ClickFilterDecision::decide(
            $settings,
            $entry,
            static fn (): bool => $signedIn,
            static fn (): bool => $crawler,
            static fn (): bool => $repeat,
        );

        return [
            'ip' => $address,
            'filtered' => $verdict->filtered,
            'reason' => $verdict->reason,
            'entry' => $entry?->toArray(),
            'explanation' => self::explain($verdict, $entry, $settings, $seen, strlen($packed) === 16 ? '/48' : '/24'),
            'checks' => [
                'filter_list' => $entry?->toArray(),
                'sign_in_address' => [
                    'enabled' => $settings->signInAddresses,
                    'matches' => $signedIn,
                    'asked_of' => 'your own last sign-in address only; a click is also filtered from another user\'s',
                ],
                'crawler_range' => ['enabled' => $settings->crawlerRanges, 'matches' => $crawler],
                'repeat_visit' => [
                    'enabled' => $settings->repeatVisits,
                    'window' => $settings->repeatWindow,
                    'matches' => $repeat,
                ],
            ],
            'not_tested' => 'bot detection, which reads the visitor\'s browser rather than the address',
        ];
    }

    /** 'address' or 'block' (the masked address) when a click of the account is in the window, else null. */
    private function recentlySeen(int $userId, string $address, ?int $within): ?string
    {
        foreach (['address' => $address, 'block' => StoredVisitorIp::mask($address)] as $form => $stored) {
            if ($this->seenAs($userId, StoredAddressIds::of($this->conn, $stored), $within)) {
                return $form;
            }
        }

        return null;
    }

    /** @param list<int> $ids */
    private function seenAs(int $userId, array $ids, ?int $within): bool
    {
        if ($ids === []) {
            return false;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'SELECT 1 FROM 202_last_ips WHERE user_id = ? AND ip_id IN (' . $in . ')';
        $types = 'i' . str_repeat('i', count($ids));
        $values = [$userId, ...$ids];
        if ($within !== null) {
            $sql .= ' AND time >= ?';
            $types .= 'i';
            $values[] = time() - $within;
        }
        $stmt = $this->conn->prepareRead($sql . ' LIMIT 1');
        $this->conn->bind($stmt, $types, $values);

        return $this->conn->fetchOne($stmt) !== null;
    }

    /** @param ?string $seen recentlySeen()'s answer */
    private static function explain(
        ClickFilterVerdict $verdict,
        ?ClickFilterEntry $entry,
        ClickFilterSettings $settings,
        ?string $seen,
        string $block
    ): string {
        $entryWords = $entry === null ? '' : $entry->range->text() . ' (entry ' . $entry->id
            . ($entry->note !== '' ? ', "' . $entry->note . '"' : '') . ')';
        $default = $settings->repeatWindow === ClickFilterSettings::DEFAULT_WINDOW;
        $window = $default ? 'until the daily cleanup clears it' : 'within ' . $settings->repeatWindow;
        $clicked = $seen === 'block'
            ? 'an address in its ' . $block . ' clicked already (under privacy the repeat check knows only the ' . $block . ')'
            : 'it clicked already';

        return match ($verdict->reason) {
            ClickFilterVerdict::FILTER_LIST => 'Filtered: it is in ' . $entryWords . ', which is always filtered.',
            ClickFilterVerdict::SIGN_IN_ADDRESS => 'Filtered: you last signed in from it.',
            ClickFilterVerdict::CRAWLER_RANGE => 'Filtered: it is in one of the built-in search engine crawler ranges.',
            ClickFilterVerdict::REPEAT_VISIT => 'Filtered: ' . $clicked . ', and a repeat visit is filtered ' . $window . '.',
            default => $entry !== null
                ? 'Counted: it is in ' . $entryWords . ', which is always counted, whatever the other rules say.'
                : 'Counted: no rule filters it.' . ($settings->repeatVisits && $seen === null
                    ? ' Its next click ' . ($default ? 'before the daily cleanup' : 'within ' . $settings->repeatWindow)
                        . ' would be filtered as a repeat visit.'
                    : ''),
        };
    }
}
