<?php

declare(strict_types=1);

namespace Tests\Click\Filter;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\Filter\AddressRange;
use Prosper202\Click\Filter\ClickFilterEntry;
use Prosper202\Click\Filter\ClickFilterRules;
use Prosper202\Click\Filter\ClickFilterSettings;

/**
 * The one rule Personal settings' Click filtering card and
 * /users/{id}/click-filter ask: what each field takes, and what it refuses
 * by name instead of storing something else (CLAUDE.md #4).
 */
final class ClickFilterRulesTest extends TestCase
{
    public function testOnlyTheFieldsGivenChange(): void
    {
        $current = new ClickFilterSettings(true, false, true, '6h');
        [$next, $errors] = ClickFilterRules::settings(['repeat_window' => '7d'], $current);

        self::assertSame([], $errors);
        self::assertSame(
            ['sign_in_addresses' => true, 'crawler_ranges' => false, 'repeat_visits' => true, 'repeat_window' => '7d'],
            $next->toArray()
        );
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function flags(): iterable
    {
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield '1' => [1, true];
        yield '0' => [0, false];
        yield "'1' (a form's box)" => ['1', true];
        yield "'0'" => ['0', false];
        yield "'true'" => ['true', true];
        yield "'false', which (bool) reads as true" => ['false', false];
    }

    /** @dataProvider flags */
    public function testASwitchTakesTrueOrFalse(mixed $value, bool $expected): void
    {
        [$next, $errors] = ClickFilterRules::settings(['crawler_ranges' => $value], ClickFilterSettings::defaults());

        self::assertSame([], $errors);
        self::assertSame($expected, $next->crawlerRanges);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function refusedSettings(): iterable
    {
        yield 'a switch as yes' => ['repeat_visits', 'yes'];
        yield 'a switch as 2' => ['sign_in_addresses', 2];
        yield 'a switch as empty' => ['crawler_ranges', ''];
        yield 'a switch as null' => ['crawler_ranges', null];
        yield 'a window not offered' => ['repeat_window', '2h'];
        yield 'a window in seconds' => ['repeat_window', 86400];
        yield 'a window off' => ['repeat_window', 'off'];
        yield 'a window in capitals' => ['repeat_window', '24H'];
    }

    /** @dataProvider refusedSettings */
    public function testASettingItCannotReadIsRefusedByName(string $field, mixed $value): void
    {
        [$next, $errors] = ClickFilterRules::settings([$field => $value], ClickFilterSettings::defaults());

        self::assertArrayHasKey($field, $errors);
        self::assertTrue($next->isDefault(), 'nothing refused is taken');
    }

    public function testANewEntryNeedsItsAddressAndItsAction(): void
    {
        [, , , $errors] = ClickFilterRules::newEntry([]);
        self::assertSame(['address', 'action'], array_keys($errors));

        [$range, $action, $note, $errors] = ClickFilterRules::newEntry([
            'address' => '2001:db8::/32', 'action' => 'count', 'note' => '  QA office  ',
        ]);
        self::assertSame([], $errors);
        self::assertSame('2001:db8::/32', $range?->text());
        self::assertSame('count', $action);
        self::assertSame('QA office', $note);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusedEntries(): iterable
    {
        yield 'an action not offered' => [['address' => '203.0.113.7', 'action' => 'allow'], 'action'];
        yield 'an action as a list' => [['address' => '203.0.113.7', 'action' => ['count']], 'action'];
        yield 'a bad address' => [['address' => '203.0.113.7/24', 'action' => 'filter'], 'address'];
        yield 'a note too long' => [['address' => '203.0.113.7', 'action' => 'filter', 'note' => str_repeat('é', 256)], 'note'];
        yield 'a note of two lines' => [['address' => '203.0.113.7', 'action' => 'filter', 'note' => "a\nb"], 'note'];
        yield 'a note that is no text' => [['address' => '203.0.113.7', 'action' => 'filter', 'note' => 5], 'note'];
        yield 'a note that is no UTF-8' => [['address' => '203.0.113.7', 'action' => 'filter', 'note' => "\xff\xfe"], 'note'];
    }

    /**
     * @dataProvider refusedEntries
     * @param array<string, mixed> $input
     */
    public function testAnEntryFieldItCannotReadIsRefusedByName(array $input, string $field): void
    {
        [, , , $errors] = ClickFilterRules::newEntry($input);

        self::assertSame([$field], array_keys($errors));
    }

    public function testANoteOf255CharactersIsTaken(): void
    {
        [, , $note, $errors] = ClickFilterRules::newEntry([
            'address' => '203.0.113.7', 'action' => 'filter', 'note' => str_repeat('é', 255),
        ]);

        self::assertSame([], $errors);
        self::assertSame(255, mb_strlen($note));
    }

    public function testAChangedEntryKeepsWhatIsNotGivenAndCannotBeEmptied(): void
    {
        $current = new ClickFilterEntry(4, AddressRange::parse('198.51.100.0/24'), 'filter', 'farm', 1);

        [$range, $action, $note, $errors] = ClickFilterRules::changedEntry(['action' => 'count'], $current);
        self::assertSame([], $errors);
        self::assertSame(['198.51.100.0/24', 'count', 'farm'], [$range->text(), $action, $note]);

        [, , $note, $errors] = ClickFilterRules::changedEntry(['note' => ''], $current);
        self::assertSame([], $errors);
        self::assertSame('', $note, 'a note is cleared with ""');

        [, , , $errors] = ClickFilterRules::changedEntry(['address' => '', 'action' => null], $current);
        self::assertSame(['address', 'action'], array_keys($errors));
    }

    /** @return iterable<string, array{mixed, ?string}> */
    public static function addressesToTest(): iterable
    {
        yield 'IPv4' => ['203.0.113.7', '203.0.113.7'];
        yield 'IPv6' => ['2001:DB8::7', '2001:db8::7'];
        yield 'IPv4-mapped IPv6, as IPv4' => ['::ffff:203.0.113.7', '203.0.113.7'];
        yield 'a block' => ['203.0.113.0/24', null];
        yield 'a word' => ['office', null];
        yield 'empty' => ['', null];
        yield 'a list' => [['203.0.113.7'], null];
    }

    /** @dataProvider addressesToTest */
    public function testOneAddressIsTested(mixed $value, ?string $expected): void
    {
        [$packed, $errors] = ClickFilterRules::testAddress($value);

        if ($expected === null) {
            self::assertNull($packed);
            self::assertArrayHasKey('ip', $errors);
        } else {
            self::assertSame([], $errors);
            self::assertSame($expected, inet_ntop((string) $packed));
        }
    }
}
