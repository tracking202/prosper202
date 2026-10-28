<?php

declare(strict_types=1);

namespace Tests\OfflineConversion;

use PHPUnit\Framework\TestCase;
use Prosper202\OfflineConversion\ExportOptions;
use Prosper202\OfflineConversion\Format;
use Prosper202\OfflineConversion\LeftOut;
use Prosper202\OfflineConversion\OfflineExport;

/**
 * Which conversions a file leaves out, decided one row at a time, each by
 * the first reason that applies. Every reason is reached from a row that
 * passes every check above it, so each case below trips only the check it
 * names.
 */
final class ReasonForTest extends TestCase
{
    private const NOW = 1_800_000_000;

    /** @return array<string, mixed> a row OfflineExport::query() reads, which every check passes */
    private static function row(array $over = []): array
    {
        return $over + [
            'conv_id' => 9,
            'click_time' => self::NOW - 86400,
            'conv_time' => self::NOW - 3600,
            'match_method' => 'click_id',
            'counted_amount' => '12.50000',
            'gclid' => 'EAIaIQ_gclid-1',
            'gbraid' => '',
            'wbraid' => '',
            'msclkid' => '0123456789abcdef0123456789abcdef',
            'conversion_name' => 'Lead',
            'owned' => 1,
        ];
    }

    private static function reason(array $over, Format $format = Format::GOOGLE, ?string $name = 'Lead'): ?LeftOut
    {
        return OfflineExport::reasonFor(self::row($over), new ExportOptions($format, 30, self::NOW), $name);
    }

    public function testARowThatPassesEveryCheckIsALine(): void
    {
        foreach (Format::cases() as $format) {
            self::assertNull(self::reason([], $format), $format->value);
        }
        self::assertNull(self::reason(['match_method' => 'cookie']), 'a tracking cookie names its click');
        self::assertNull(self::reason(['counted_amount' => '0.00000']), 'a conversion that counts for 0 is one');
    }

    public function testEachReasonInItsOrder(): void
    {
        self::assertSame(LeftOut::NOT_COUNTED, self::reason(['counted_amount' => null, 'match_method' => 'same_ip', 'gclid' => '']));
        self::assertSame(LeftOut::MATCHED_BY_VISITOR_ID, self::reason(['match_method' => 'visitor_id', 'gclid' => '']));
        self::assertSame(LeftOut::MATCHED_BY_SAME_IP, self::reason(['match_method' => 'same_ip']));
        self::assertSame(LeftOut::MATCH_UNKNOWN, self::reason(['match_method' => 'unknown']));
        self::assertSame(LeftOut::MATCH_UNKNOWN, self::reason(['match_method' => 'guessed']), 'a value no writer produces names nothing');
        self::assertSame(LeftOut::NO_CLICK_ID, self::reason(['gclid' => '', 'owned' => null]));
        self::assertSame(LeftOut::NO_CLICK_ID, self::reason(['msclkid' => '', 'owned' => null], Format::MICROSOFT));
        self::assertSame(LeftOut::GBRAID_WBRAID_ONLY, self::reason(['gclid' => '', 'wbraid' => 'CkIK', 'owned' => null], Format::GOOGLE_UPLOADS));
        self::assertNull(self::reason(['gclid' => '', 'wbraid' => 'CkIK']), 'the Data Manager file takes a wbraid alone');
        self::assertSame(LeftOut::MALFORMED_CLICK_ID, self::reason(['gbraid' => '=cmd|x']));
        self::assertSame(LeftOut::NOT_ACCOUNTS_CLICK, self::reason(['owned' => 0]));
        self::assertSame(LeftOut::NOT_ACCOUNTS_CLICK, self::reason(['owned' => null]), 'not asked is not a yes');
        self::assertSame(LeftOut::CLICK_TOO_OLD, self::reason(['click_time' => self::NOW - OfflineExport::CLICK_MAX_AGE - 1, 'conv_time' => self::NOW - 60]));
        self::assertNull(self::reason(['click_time' => self::NOW - OfflineExport::CLICK_MAX_AGE, 'conv_time' => self::NOW - 60]));
        self::assertSame(LeftOut::BEFORE_CLICK, self::reason(['conv_time' => self::NOW - 86401]));
        self::assertSame(LeftOut::CLICK_TOO_RECENT, self::reason(['click_time' => self::NOW - OfflineExport::HOLD + 1, 'conv_time' => self::NOW]));
        self::assertNull(self::reason(['click_time' => self::NOW - OfflineExport::HOLD, 'conv_time' => self::NOW]));
        self::assertSame(LeftOut::NEGATIVE_VALUE, self::reason(['counted_amount' => '-0.00001']));
        self::assertSame(LeftOut::NO_CONVERSION_NAME, self::reason([], Format::GOOGLE, null));
    }

    public function testEveryReasonIsReachedAndExplained(): void
    {
        $reached = [];
        foreach ([
            [['counted_amount' => null]], [['match_method' => 'visitor_id']], [['match_method' => 'same_ip']],
            [['match_method' => 'unknown']], [['gclid' => '']], [['gclid' => '', 'gbraid' => 'b'], Format::GOOGLE_UPLOADS],
            [['gclid' => 'a b']], [['owned' => 0]], [['click_time' => 1, 'conv_time' => self::NOW]],
            [['conv_time' => 1]], [['click_time' => self::NOW, 'conv_time' => self::NOW]],
            [['counted_amount' => '-1']], [[], Format::GOOGLE, null],
        ] as $case) {
            $reached[] = self::reason($case[0], $case[1] ?? Format::GOOGLE, array_key_exists(2, $case) ? $case[2] : 'Lead')?->value;
        }
        $reached[] = LeftOut::OVER_ROW_LIMIT->value; // OfflineExportIntegrationTest reaches it
        self::assertSame(LeftOut::values(), $reached, 'every reason, in the enum\'s order');
        foreach (LeftOut::cases() as $reason) {
            self::assertStringEndsWith('.', $reason->explanation(), $reason->value);
        }
    }

    public function testTheIdsAreTheLayoutsInItsOrderWithoutTheEmptyOnes(): void
    {
        $row = self::row(['gbraid' => 'b', 'wbraid' => '']);
        self::assertSame(['gclid' => 'EAIaIQ_gclid-1', 'gbraid' => 'b'], OfflineExport::ids($row, Format::GOOGLE));
        self::assertSame(['gclid' => 'EAIaIQ_gclid-1'], OfflineExport::ids($row, Format::GOOGLE_UPLOADS));
        self::assertSame(['msclkid' => '0123456789abcdef0123456789abcdef'], OfflineExport::ids($row, Format::MICROSOFT));
    }

    /** The read asks whether the click is the account's, tied to the conversion's account (CLAUDE.md #27). */
    public function testTheReadTiesEveryJoinToTheAccount(): void
    {
        [$sql, $types, $values] = OfflineExport::query(new ExportOptions(Format::GOOGLE, 7, self::NOW, 12), 5);
        self::assertStringContainsString('oc.click_id = cl.click_id AND oc.user_id = cl.user_id', $sql);
        self::assertStringContainsString('cn.user_id = cl.user_id', $sql);
        self::assertStringContainsString('dn.user_id = cl.user_id', $sql);
        self::assertStringContainsString('WHERE cl.user_id = ?', $sql);
        self::assertStringContainsString("(g.gclid <> '' OR g.gbraid <> '' OR g.wbraid <> '')", $sql);
        self::assertSame('ssiiii', $types);
        self::assertSame(['google', 'google', 5, self::NOW - 7 * 86400, self::NOW, 12], $values);
        [$sql] = OfflineExport::query(new ExportOptions(Format::MICROSOFT, 7, self::NOW), 5);
        self::assertStringContainsString("(g.msclkid <> '')", $sql);
    }
}
