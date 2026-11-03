<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Setup\FacebookPixelAssistantSettings;
use Tests\Support\FakeMysqliConnection;

/**
 * A landing page's Bot202 Facebook Pixel Assistant settings, as Setup ›
 * Landing Pages posts and stores them (FacebookPixelAssistantSettings).
 * FacebookPixelAssistantIntegrationTest stores and reads them against a
 * real database across two accounts.
 */
final class FacebookPixelAssistantSettingsTest extends TestCase
{
    public function testTheFormsSettingsAreRead(): void
    {
        $errors = [];
        $settings = FacebookPixelAssistantSettings::fromForm([
            'fbpa_status' => '1',
            'fbpa_dynamic_epv' => 'on',
            'fbpa_content_name' => '  Spring sale  ',
            'fbpa_content_type' => '6',
            'fbpa_outbound_clicks' => '2',
        ], $errors);

        self::assertSame([], $errors);
        self::assertSame(
            ['status' => 1, 'dynamic_epv' => 1, 'content_name' => 'Spring sale', 'content_type' => 6, 'outbound_clicks' => 2],
            $settings
        );
    }

    public function testSwitchesLeftOffAndNoChoiceAreOffAndNone(): void
    {
        $errors = [];
        $settings = FacebookPixelAssistantSettings::fromForm(['fbpa_status' => '0', 'fbpa_content_type' => '0'], $errors);

        self::assertSame([], $errors);
        self::assertSame(
            ['status' => 0, 'dynamic_epv' => 0, 'content_name' => '', 'content_type' => 0, 'outbound_clicks' => 0],
            $settings
        );
    }

    /** What cannot be stored as sent is refused, never cut or dropped. */
    public function testWhatCannotBeStoredIsRefused(): void
    {
        $errors = [];
        FacebookPixelAssistantSettings::fromForm([
            'fbpa_status' => '1',
            'fbpa_content_name' => str_repeat('é', 101),
            'fbpa_content_type' => '7',
            'fbpa_outbound_clicks' => ['1'],
        ], $errors);

        self::assertSame(['fbpa_content_name', 'fbpa_content_type', 'fbpa_outbound_clicks'], array_keys($errors));

        $errors = [];
        FacebookPixelAssistantSettings::fromForm(['fbpa_content_name' => str_repeat('é', 100), 'fbpa_outbound_clicks' => '01'], $errors);
        self::assertSame(['fbpa_outbound_clicks'], array_keys($errors), 'a 100-character name fits; 01 is not an id');

        $errors = [];
        FacebookPixelAssistantSettings::fromForm(['fbpa_content_name' => ['x']], $errors);
        self::assertSame(['fbpa_content_name'], array_keys($errors), 'a list is no name');
    }

    /** The row is written only for a page of the user's: selected from 202_landing_pages under the user's id. */
    public function testSaveWritesOnlyThroughTheUsersPage(): void
    {
        $db = new FakeMysqliConnection();
        (new FacebookPixelAssistantSettings(new Connection($db)))->save(7, 41, [
            'status' => 1, 'dynamic_epv' => 0, 'content_name' => 'Plan', 'content_type' => 1, 'outbound_clicks' => 2,
        ]);

        $writes = $db->statementsContaining('INSERT INTO 202_bot202_facebook_pixel_assistant');
        self::assertCount(1, $writes);
        self::assertMatchesRegularExpression('/FROM 202_landing_pages AS lp\s+WHERE lp\.landing_page_id = \? AND lp\.user_id = \?/', $writes[0]->sql);
        self::assertSame('iisiiii', $writes[0]->boundTypes);
        self::assertSame([1, 0, 'Plan', 1, 2, 41, 7], $writes[0]->boundValues);
        self::assertSame(1, $writes[0]->executeCount);
    }

    public function testLoadReadsOnlyTheUsersPage(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows('FROM 202_bot202_facebook_pixel_assistant AS fb', [[
            'b202_fbpa_status' => '1', 'b202_fbpa_dynamic_epv' => '1', 'b202_fbpa_content_name' => null,
            'b202_fbpa_content_type' => '3', 'b202_fbpa_outbound_clicks' => '1',
        ]]);

        $settings = (new FacebookPixelAssistantSettings(new Connection($db)))->load(7, 41);

        self::assertSame(['status' => 1, 'dynamic_epv' => 1, 'content_name' => '', 'content_type' => 3, 'outbound_clicks' => 1], $settings);
        $read = $db->statementsContaining('FROM 202_bot202_facebook_pixel_assistant AS fb');
        self::assertStringContainsString('lp.user_id = ?', $read[0]->sql);
        self::assertSame([7, 41], $read[0]->boundValues);
    }

    /**
     * The lookup rows are paid 1.9.56's, byte for byte: the 1.9.54 upgrade
     * step's hex literals, decoded, are the rows seedStatements() writes.
     */
    public function testTheSeedRowsArePaid1956s(): void
    {
        $upgrade = (string) file_get_contents(dirname(__DIR__, 2) . '/202-config/functions-upgrade.php');
        $paid = [];
        foreach (['202_bot202_facebook_pixel_content_type', '202_bot202_facebook_pixel_click_events'] as $table) {
            self::assertSame(1, preg_match('/INSERT INTO `' . $table . '`[^;]*?VALUES(.*?);/s', $upgrade, $m), $table);
            preg_match_all("/\((\d+),X'([0-9A-F]+)',X'([0-9A-F]+)'\)/", $m[1], $rows, PREG_SET_ORDER);
            foreach ($rows as $row) {
                $paid[$table][(int) $row[1]] = [(string) hex2bin($row[2]), (string) hex2bin($row[3])];
            }
        }

        self::assertSame($paid['202_bot202_facebook_pixel_content_type'], FacebookPixelAssistantSettings::CONTENT_TYPES);
        self::assertSame($paid['202_bot202_facebook_pixel_click_events'], FacebookPixelAssistantSettings::CLICK_EVENTS);
        foreach (FacebookPixelAssistantSettings::seedStatements() as $sql) {
            self::assertStringStartsWith('INSERT IGNORE INTO `202_bot202_facebook_pixel_', $sql);
        }
    }
}
