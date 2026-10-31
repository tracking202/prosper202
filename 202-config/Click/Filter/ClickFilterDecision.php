<?php

declare(strict_types=1);

namespace Prosper202\Click\Filter;

/**
 * The click filter's one rule, for a click (FILTER::verdict()) and for an
 * address tested on the page or the API (ClickFilterTester), so the two
 * cannot answer differently.
 *
 * An "always count" entry beats every check, an "always filter" entry beats
 * the built-in ones, and the built-in checks run in the order they always
 * did, each only when the one before did not filter and only when switched
 * on: the sign-in addresses, the crawler ranges, then the repeat visit,
 * which records the address when it does not filter (FILTER::checkLastIps()),
 * so it is asked last and lazily. With the defaults and no entries the
 * checks run exactly as before the filter had settings.
 */
final class ClickFilterDecision
{
    private function __construct()
    {
    }

    /**
     * @param ClickFilterEntry|null $entry the list entry the address falls in (MysqlClickFilterStore::match())
     * @param callable(): bool $signedIn
     * @param callable(): bool $crawler
     * @param callable(?int): bool $repeat given ClickFilterSettings::repeatWithin()
     */
    public static function decide(
        ClickFilterSettings $settings,
        ?ClickFilterEntry $entry,
        callable $signedIn,
        callable $crawler,
        callable $repeat
    ): ClickFilterVerdict {
        if ($entry !== null && $entry->action === ClickFilterEntry::COUNT) {
            return ClickFilterVerdict::counted($entry->id);
        }
        if ($entry !== null) {
            return ClickFilterVerdict::filtered(ClickFilterVerdict::FILTER_LIST, $entry->id);
        }
        if ($settings->signInAddresses && $signedIn()) {
            return ClickFilterVerdict::filtered(ClickFilterVerdict::SIGN_IN_ADDRESS);
        }
        if ($settings->crawlerRanges && $crawler()) {
            return ClickFilterVerdict::filtered(ClickFilterVerdict::CRAWLER_RANGE);
        }
        if ($settings->repeatVisits && $repeat($settings->repeatWithin())) {
            return ClickFilterVerdict::filtered(ClickFilterVerdict::REPEAT_VISIT);
        }

        return ClickFilterVerdict::counted();
    }
}
