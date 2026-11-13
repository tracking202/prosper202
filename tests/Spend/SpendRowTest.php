<?php

declare(strict_types=1);

namespace Tests\Spend;

use PHPUnit\Framework\TestCase;
use Prosper202\Spend\SpendRow;

/**
 * A spend row is read exactly or refused by field name (CLAUDE.md #4).
 */
final class SpendRowTest extends TestCase
{
    private const NOW = 1794300000;
    private const HOUR = 1794297600;

    /** @return array<string, mixed> */
    private static function row(array $over = []): array
    {
        return $over + ['platform' => 'openai_ads', 'account_ref' => 'adacct_demo', 'campaign_ref' => 'cmp_1',
            'ad_group_ref' => 'adgrp_1', 'ad_ref' => 'ad_1', 'hour_start' => self::HOUR, 'impressions' => 10,
            'clicks' => 2, 'spend' => 1.25, 'currency' => 'USD'];
    }

    public function testAWholeRowIsRead(): void
    {
        [$row, $errors] = SpendRow::parse(self::row(), 'rows.0.', self::NOW);
        self::assertSame([], $errors);
        self::assertNotNull($row);
        self::assertSame('1.250000', $row->spend);
        self::assertSame(self::HOUR, $row->hourStart);
        self::assertSame(2, $row->platformClicks);
    }

    public function testOptionalRefsAreNoneWhenNullOrAbsent(): void
    {
        $data = self::row(['campaign_ref' => null]);
        unset($data['ad_group_ref']);
        [$row] = SpendRow::parse($data, '', self::NOW);
        self::assertSame(['', ''], [$row?->campaignRef, $row?->adGroupRef]);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refused(): iterable
    {
        yield 'unknown field' => [['cost' => 1], 'cost'];
        yield 'spend with seven decimals' => [['spend' => 0.1234567], 'spend'];
        yield 'spend as a string with seven decimals' => [['spend' => '0.1234567'], 'spend'];
        yield 'negative spend' => [['spend' => -1], 'spend'];
        yield 'spend in minor units text' => [['spend' => '1,25'], 'spend'];
        // An hour that has started, off the hour: only the on-the-hour check refuses it.
        yield 'hour not on the hour' => [['hour_start' => self::HOUR - 3600 + 60], 'hour_start'];
        yield 'hour 0' => [['hour_start' => 0], 'hour_start'];
        yield 'hour before 2000' => [['hour_start' => 946681200], 'hour_start'];
        yield 'ad with a trailing space' => [['ad_ref' => 'ad_1 '], 'ad_ref'];
        yield 'account with a leading space' => [['account_ref' => ' adacct'], 'account_ref'];
        yield 'campaign with a trailing space' => [['campaign_ref' => 'cmp '], 'campaign_ref'];
        yield 'hour not started' => [['hour_start' => self::HOUR + 3600], 'hour_start'];
        yield 'hour as a float' => [['hour_start' => 1794297600.0], 'hour_start'];
        yield 'clicks as 12abc' => [['clicks' => '12abc'], 'clicks'];
        yield 'impressions negative' => [['impressions' => -3], 'impressions'];
        yield 'currency lower case' => [['currency' => 'usd'], 'currency'];
        yield 'empty ad' => [['ad_ref' => ''], 'ad_ref'];
        yield 'ad too long' => [['ad_ref' => str_repeat('a', 129)], 'ad_ref'];
        yield 'ad with a control character' => [['ad_ref' => "ad\n1"], 'ad_ref'];
        yield 'ad not ASCII' => [['ad_ref' => 'ad_é7'], 'ad_ref'];
        yield 'platform capitals' => [['platform' => 'OpenAI'], 'platform'];
    }

    /** @dataProvider refused */
    public function testAValueThatIsNotExactIsRefusedByName(array $over, string $field): void
    {
        [$row, $errors] = SpendRow::parse(self::row($over), 'rows.4.', self::NOW);
        self::assertNull($row);
        self::assertArrayHasKey('rows.4.' . $field, $errors);
    }

    public function testTheHourChecksSayWhichRuleRefused(): void
    {
        [, $errors] = SpendRow::parse(self::row(['hour_start' => self::HOUR - 3600 + 60]), '', self::NOW);
        self::assertStringContainsString('a multiple of 3600', $errors['hour_start']);
        [, $errors] = SpendRow::parse(self::row(['hour_start' => self::HOUR + 3600]), '', self::NOW);
        self::assertSame('is an hour that has not started yet', $errors['hour_start']);
        [, $errors] = SpendRow::parse(self::row(['hour_start' => 0]), '', self::NOW);
        self::assertStringContainsString('before 2000-01-01', $errors['hour_start']);
    }

    public function testAMissingRequiredFieldIsNamed(): void
    {
        $data = self::row();
        unset($data['spend'], $data['currency']);
        [$row, $errors] = SpendRow::parse($data, '', self::NOW);
        self::assertNull($row);
        self::assertSame('is required', $errors['spend']);
        self::assertSame('is required', $errors['currency']);
    }

    public function testKeysDoNotJoinAcrossFields(): void
    {
        [$a] = SpendRow::parse(self::row(['account_ref' => 'ab', 'ad_ref' => 'c']), '', self::NOW);
        [$b] = SpendRow::parse(self::row(['account_ref' => 'a', 'ad_ref' => 'bc']), '', self::NOW);
        self::assertNotSame($a?->key(), $b?->key());
    }
}
