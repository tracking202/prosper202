<?php
// tests/Messaging/MigrationColumnsTest.php
use PHPUnit\Framework\TestCase;

final class MigrationColumnsTest extends TestCase
{
    /**
     * The 1.9.78 -> 1.9.79 step reconciles 202_users_pref and
     * 202_messaging_events from the installer's definitions, so the consent
     * columns must be in those definitions and both tables in that step.
     */
    public function test_upgrade_reconciles_the_consent_columns(): void
    {
        $upgrade = file_get_contents(__DIR__ . '/../../202-config/functions-upgrade.php');
        $at = strpos($upgrade, 'if ($prosper202_version == \'1.9.78\')');
        $this->assertNotFalse($at, 'the 1.9.78 step must exist');
        $step = substr($upgrade, $at);
        $end = strpos($step, "version='1.9.79'");
        $this->assertNotFalse($end);
        $step = substr($step, 0, $end);
        $this->assertStringContainsString('UserTables::usersPref()', $step);
        $this->assertStringContainsString('CoreTables::messagingEvents()', $step);

        $sql = file_get_contents(__DIR__ . '/../../202-config/Database/Tables/UserTables.php');
        foreach ([
            'analytics_consent', 'analytics_consent_at', 'analytics_consent_source',
            'email_marketing_consent', 'email_marketing_consent_at',
            'eu_consent_prompt_seen', 'analytics_geo_is_eu',
        ] as $col) {
            $this->assertStringContainsString($col, $sql, "installer 202_users_pref missing column $col");
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
