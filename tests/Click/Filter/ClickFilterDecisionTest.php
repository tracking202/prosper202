<?php

declare(strict_types=1);

namespace Tests\Click\Filter;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\Filter\AddressRange;
use Prosper202\Click\Filter\ClickFilterDecision;
use Prosper202\Click\Filter\ClickFilterEntry;
use Prosper202\Click\Filter\ClickFilterSettings;
use Prosper202\Click\Filter\ClickFilterVerdict;

/**
 * The click filter's one rule: which check decides, in which order, and
 * which checks are asked at all. The repeat check records the address when
 * it does not filter, so asking it is a write: the defaults have to ask
 * exactly what the filter asked before it had settings.
 */
final class ClickFilterDecisionTest extends TestCase
{
    /** @var list<string> the checks asked, in order */
    private array $asked = [];

    /** @var list<?int> the windows the repeat check was asked with */
    private array $windows = [];

    /**
     * @param array{signedIn?: bool, crawler?: bool, repeat?: bool} $answers
     */
    private function decide(ClickFilterSettings $settings, ?ClickFilterEntry $entry, array $answers): ClickFilterVerdict
    {
        $this->asked = [];
        $this->windows = [];

        return ClickFilterDecision::decide(
            $settings,
            $entry,
            function () use ($answers): bool {
                $this->asked[] = 'sign_in';
                return $answers['signedIn'] ?? false;
            },
            function () use ($answers): bool {
                $this->asked[] = 'crawler';
                return $answers['crawler'] ?? false;
            },
            function (?int $within) use ($answers): bool {
                $this->asked[] = 'repeat';
                $this->windows[] = $within;
                return $answers['repeat'] ?? false;
            },
        );
    }

    private static function entry(string $action, int $id = 7): ClickFilterEntry
    {
        return new ClickFilterEntry($id, AddressRange::parse('203.0.113.0/24'), $action, '', 1);
    }

    /**
     * The defaults and no list: the filter as it was, check for check.
     *
     * @return iterable<string, array{array<string, bool>, int, ?string, list<string>}>
     */
    public static function defaults(): iterable
    {
        yield 'nothing matches' => [[], 0, null, ['sign_in', 'crawler', 'repeat']];
        yield 'a sign-in address' => [['signedIn' => true, 'crawler' => true, 'repeat' => true], 1, 'sign_in_address', ['sign_in']];
        yield 'a crawler' => [['crawler' => true, 'repeat' => true], 1, 'crawler_range', ['sign_in', 'crawler']];
        yield 'a repeat visit' => [['repeat' => true], 1, 'repeat_visit', ['sign_in', 'crawler', 'repeat']];
    }

    /**
     * @dataProvider defaults
     * @param array<string, bool> $answers
     * @param list<string> $asked
     */
    public function testTheDefaultsAskWhatTheFilterAlwaysAsked(array $answers, int $flag, ?string $reason, array $asked): void
    {
        $verdict = $this->decide(ClickFilterSettings::defaults(), null, $answers);

        self::assertSame($flag, $verdict->flag());
        self::assertSame($reason, $verdict->reason);
        self::assertNull($verdict->entryId);
        self::assertSame($asked, $this->asked);
        if (in_array('repeat', $asked, true)) {
            self::assertSame([null], $this->windows, 'the default window is the daily cleanup\'s: no time bound');
        }
    }

    public function testAlwaysCountBeatsEveryCheckAndAsksNone(): void
    {
        $verdict = $this->decide(ClickFilterSettings::defaults(), self::entry(ClickFilterEntry::COUNT, 3), [
            'signedIn' => true, 'crawler' => true, 'repeat' => true,
        ]);

        self::assertFalse($verdict->filtered);
        self::assertNull($verdict->reason);
        self::assertSame(3, $verdict->entryId, 'the entry that counted it is said');
        self::assertSame([], $this->asked, 'no check is asked, so the repeat check records nothing');
    }

    public function testAlwaysFilterBeatsTheBuiltInChecks(): void
    {
        $verdict = $this->decide(ClickFilterSettings::defaults(), self::entry(ClickFilterEntry::FILTER, 9), [
            'signedIn' => true,
        ]);

        self::assertTrue($verdict->filtered);
        self::assertSame(ClickFilterVerdict::FILTER_LIST, $verdict->reason);
        self::assertSame(9, $verdict->entryId);
        self::assertSame([], $this->asked);
    }

    public function testAlwaysFilterFiltersWithEveryCheckOff(): void
    {
        $verdict = $this->decide(new ClickFilterSettings(false, false, false), self::entry(ClickFilterEntry::FILTER), []);

        self::assertSame(ClickFilterVerdict::FILTER_LIST, $verdict->reason);
    }

    public function testACheckSwitchedOffIsNotAsked(): void
    {
        $verdict = $this->decide(new ClickFilterSettings(false, true, true), null, ['signedIn' => true]);
        self::assertFalse($verdict->filtered, 'a sign-in address is counted with that check off');
        self::assertSame(['crawler', 'repeat'], $this->asked);

        $verdict = $this->decide(new ClickFilterSettings(true, false, true), null, ['crawler' => true]);
        self::assertFalse($verdict->filtered, 'a crawler is counted with that check off');
        self::assertSame(['sign_in', 'repeat'], $this->asked);

        $verdict = $this->decide(new ClickFilterSettings(true, true, false), null, ['repeat' => true]);
        self::assertFalse($verdict->filtered, 'a repeat visit is counted with that check off');
        self::assertSame(['sign_in', 'crawler'], $this->asked, 'and the address is not recorded');

        $verdict = $this->decide(new ClickFilterSettings(false, false, false), null, ['signedIn' => true, 'crawler' => true, 'repeat' => true]);
        self::assertFalse($verdict->filtered);
        self::assertSame([], $this->asked);
    }

    /** @return iterable<string, array{string, ?int}> */
    public static function windows(): iterable
    {
        yield '1h' => ['1h', 3600];
        yield '6h' => ['6h', 21600];
        yield '24h, the default' => ['24h', null];
        yield '7d' => ['7d', 604800];
    }

    /** @dataProvider windows */
    public function testTheRepeatCheckIsAskedWithTheAccountsWindow(string $window, ?int $within): void
    {
        $this->decide(new ClickFilterSettings(true, true, true, $window), null, []);

        self::assertSame([$within], $this->windows);
    }

    public function testAWindowNotOfferedIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ClickFilterSettings(true, true, true, '2h');
    }

    public function testAVerdictNamesAnEntryExactlyWhenTheListDecided(): void
    {
        foreach ([[ClickFilterVerdict::FILTER_LIST, null], [ClickFilterVerdict::REPEAT_VISIT, 4], ['bot', null]] as [$reason, $entry]) {
            try {
                ClickFilterVerdict::filtered($reason, $entry);
                self::fail("accepted $reason with entry " . var_export($entry, true));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
