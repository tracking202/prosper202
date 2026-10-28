<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * What upgrade-equals-install forgives and what it does not. The forgiving
 * half is the one that can go silently wrong — a normalisation that swallows
 * a real difference makes the release gate green on a broken upgrade — so
 * every allowed difference sits next to a near miss that must still fail.
 */
final class SchemaDiffTest extends TestCase
{
    private const BASE = "CREATE TABLE `t` (\n"
        . "  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,\n"
        . "  `name` varchar(20) NOT NULL DEFAULT '',\n"
        . "  `note` text DEFAULT NULL COMMENT 'kept',\n"
        . "  PRIMARY KEY (`id`),\n"
        . "  KEY `a` (`name`),\n"
        . "  UNIQUE KEY `b` (`note`(10)),\n"
        . "  CONSTRAINT `t_fk` FOREIGN KEY (`id`) REFERENCES `u` (`id`)\n"
        . ") ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='table note'";

    public function testIdenticalSchemasHaveNoDifference(): void
    {
        self::assertSame([], SchemaDiff::compare(['t' => self::BASE], ['t' => self::BASE]));
    }

    public function testIndexOrderTableCommentAndCounterAreForgiven(): void
    {
        $other = str_replace(
            ["  KEY `a` (`name`),\n  UNIQUE KEY `b` (`note`(10)),\n", ' AUTO_INCREMENT=7', " COMMENT='table note'"],
            ["  UNIQUE KEY `b` (`note`(10)),\n  KEY `a` (`name`),\n", ' AUTO_INCREMENT=1', ''],
            self::BASE
        );
        self::assertNotSame(self::BASE, $other, 'the variant must actually differ');
        self::assertSame([], SchemaDiff::compare(['t' => self::BASE], ['t' => $other]));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function realDifferences(): array
    {
        return [
            'a column type' => ['`name` varchar(20)', '`name` varchar(5)'],
            'a nullability' => ["`name` varchar(20) NOT NULL DEFAULT ''", "`name` varchar(20) DEFAULT ''"],
            'a default' => ["NOT NULL DEFAULT ''", "NOT NULL DEFAULT 'x'"],
            'a column comment' => ["COMMENT 'kept'", "COMMENT 'changed'"],
            'an index definition' => ['KEY `a` (`name`)', 'KEY `a` (`name`(5))'],
            'an index kind' => ['UNIQUE KEY `b`', 'KEY `b`'],
            'a collation' => ['COLLATE=utf8mb4_general_ci', 'COLLATE=utf8mb4_bin'],
            'an engine' => ['ENGINE=InnoDB', 'ENGINE=MyISAM'],
            'a constraint' => ['REFERENCES `u` (`id`)', 'REFERENCES `v` (`id`)'],
            'a comment that is a column comment' => ["COMMENT 'kept',\n", ",\n"],
        ];
    }

    /**
     * @dataProvider realDifferences
     */
    public function testARealDifferenceIsReported(string $from, string $to): void
    {
        $other = str_replace($from, $to, self::BASE);
        self::assertNotSame(self::BASE, $other, 'the variant must actually differ');
        $differences = SchemaDiff::compare(['t' => self::BASE], ['t' => $other]);
        self::assertCount(1, $differences);
        self::assertStringStartsWith('table t differs', $differences[0]);
    }

    public function testAnExtraIndexIsReported(): void
    {
        $other = str_replace("  KEY `a` (`name`),\n", "  KEY `a` (`name`),\n  KEY `c` (`id`),\n", self::BASE);
        $differences = SchemaDiff::compare(['t' => $other], ['t' => self::BASE]);
        self::assertCount(1, $differences);
        self::assertStringContainsString('- KEY `c` (`id`)', $differences[0]);
    }

    public function testAColumnInTheWrongPlaceIsReported(): void
    {
        $moved = str_replace(
            "  `name` varchar(20) NOT NULL DEFAULT '',\n  `note` text DEFAULT NULL COMMENT 'kept',\n",
            "  `note` text DEFAULT NULL COMMENT 'kept',\n  `name` varchar(20) NOT NULL DEFAULT '',\n",
            self::BASE
        );
        self::assertNotSame(self::BASE, $moved);
        $differences = SchemaDiff::compare(['t' => $moved], ['t' => self::BASE]);
        self::assertCount(1, $differences);
        self::assertStringContainsString('same lines in a different order', $differences[0]);
    }

    public function testAMissingOrExtraTableIsReported(): void
    {
        $differences = SchemaDiff::compare(['t' => self::BASE, 'old' => self::BASE], ['t' => self::BASE, 'new' => self::BASE]);
        self::assertSame([
            'table old exists after the upgrade but not in a fresh install',
            'table new exists in a fresh install but not after the upgrade',
        ], $differences);
    }

    private static function partitioned(string $scheme, int $start, int $weeks, bool $mysql): string
    {
        $q = $mysql ? '' : '`';
        $lines = [];
        for ($i = 0; $i < $weeks; $i++) {
            $lines[] = ($i === 0 ? '(' : ' ') . "PARTITION {$q}p{$i}{$q} VALUES LESS THAN (" . ($start + $i * 604800) . ') ENGINE = InnoDB,';
        }
        $lines[] = " PARTITION {$q}p{$weeks}{$q} VALUES LESS THAN MAXVALUE ENGINE = InnoDB)" . ($mysql ? ' */' : '');

        return "CREATE TABLE `c` (\n  `click_time` int(10) unsigned NOT NULL,\n  KEY `click_time` (`click_time`)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n"
            . ($mysql ? '/*!50100 ' : ' ') . $scheme . "\n" . implode("\n", $lines);
    }

    public function testPartitionBoundariesAreForgivenOnBothServers(): void
    {
        foreach ([false, true] as $mysql) {
            $a = self::partitioned('PARTITION BY RANGE (`click_time`)', 1790365863, 158, $mysql);
            $b = self::partitioned('PARTITION BY RANGE (`click_time`)', 1790365872, 157, $mysql);
            self::assertSame([], SchemaDiff::compare(['c' => $a], ['c' => $b]), $mysql ? 'MySQL' : 'MariaDB');
        }
    }

    public function testThePartitionSchemeIsStillCompared(): void
    {
        $a = self::partitioned('PARTITION BY RANGE (`click_time`)', 1790365863, 3, false);
        $b = self::partitioned('PARTITION BY RANGE (`click_id`)', 1790365863, 3, false);
        self::assertCount(1, SchemaDiff::compare(['c' => $a], ['c' => $b]));

        $unpartitioned = "CREATE TABLE `c` (\n  `click_time` int(10) unsigned NOT NULL,\n  KEY `click_time` (`click_time`)\n"
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        self::assertCount(1, SchemaDiff::compare(['c' => $a], ['c' => $unpartitioned]));
    }

    public function testSomethingThatIsNotACreateTableIsRefusedNotCompared(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SchemaDiff::normalise('CREATE VIEW `v` AS SELECT 1');
    }
}
