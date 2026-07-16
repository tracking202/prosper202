<?php
// tests/Messaging/MigrationColumnsTest.php
use PHPUnit\Framework\TestCase;

final class MigrationColumnsTest extends TestCase
{
    public function test_upgrade_sql_adds_all_consent_columns(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../202-config/functions-upgrade.php');
        foreach ([
            'analytics_consent', 'analytics_consent_at', 'analytics_consent_source',
            'email_marketing_consent', 'email_marketing_consent_at',
            'eu_consent_prompt_seen', 'analytics_geo_is_eu',
        ] as $col) {
            $this->assertStringContainsString($col, $sql, "upgrade missing column $col");
        }
    }

    public function test_event_table_has_tier_column(): void
    {
        $files = glob(__DIR__ . '/../../202-config/Database/Tables/*.php');
        $all = '';
        foreach ($files as $f) { $all .= file_get_contents($f); }
        $this->assertMatchesRegularExpression('/202_messaging_events.*tier/s', $all);
    }
}
