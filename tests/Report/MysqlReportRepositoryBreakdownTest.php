<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Report\MysqlReportRepository;
use Prosper202\Report\ReportQuery;
use Tests\Support\FakeMysqliConnection;

/**
 * The repository's breakdown mirrors ReportsController::breakdown() and pages
 * the same way, so it needs the same id tie-breaker.
 */
final class MysqlReportRepositoryBreakdownTest extends TestCase
{
    public function testBreakdownBreaksSortTiesByIdForStablePaging(): void
    {
        $dimensions = ['campaign' => 'aff_campaign_id', 'keyword' => 'keyword_id', 'text_ad' => 'text_ad_id'];
        foreach ($dimensions as $dimension => $id) {
            $read = new FakeMysqliConnection();
            $repo = new MysqlReportRepository(new Connection(new FakeMysqliConnection(), $read));

            $repo->breakdown(new ReportQuery(7), $dimension, 'total_leads', 'ASC', 500, 500);

            $queries = $read->statementsContaining('LIMIT ? OFFSET ?');
            self::assertCount(1, $queries, $dimension);
            self::assertStringContainsString("GROUP BY ref.$id,", $queries[0]->sql, $dimension);
            self::assertMatchesRegularExpression(
                '/ORDER BY total_leads ASC, ref\.' . $id . ' ASC\s+LIMIT \? OFFSET \?$/',
                $queries[0]->sql,
                $dimension
            );
        }
    }
}
