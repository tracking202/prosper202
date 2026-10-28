<?php

declare(strict_types=1);

namespace Tests\User;

use Api\V3\Controllers\UsersController;
use Prosper202\Database\Tables\UserTables;
use Prosper202\User\PreferenceRules;
use Prosper202\User\ServedPreferences;
use Tests\TestCase;

/**
 * What GET /users/{id}/preferences serves of the row, and what a GET body
 * sent back to PUT keeps. The column lists are held to the table the
 * installer makes, so a column added without a decision fails here rather
 * than being served.
 */
final class ServedPreferencesTest extends TestCase
{
    /** The credentials the table holds today; none may move to SERVED. */
    private const KNOWN_SECRETS = [
        'cb_key', 'jvzoo_ipn_secret_key', 'zaxaa_api_signature', 'ipqs_api_key',
        'revcontent_user_id', 'revcontent_user_secret',
        'user_slack_incoming_webhook', 'lpo_site_key', 'lpo_bridge_config',
    ];

    public function testEveryColumnOfTheTableIsServedOrSecret(): void
    {
        $columns = self::usersPrefColumns();
        self::assertGreaterThan(50, count($columns), 'the users_pref column parse found almost nothing');
        foreach (['user_id', 'user_pref_limit', 'cb_key', 'lpo_bridge_config', 'user_delete_data_before'] as $anchor) {
            self::assertContains($anchor, $columns, "column discovery missed $anchor");
        }
        $listed = ServedPreferences::columns();

        $unclassified = array_values(array_diff($columns, $listed));
        self::assertSame([], $unclassified, 'these 202_users_pref columns are on neither list, so GET answers them '
            . 'as secrets: add each to ServedPreferences::SERVED if it holds no credential, or to '
            . 'ServedPreferences::SECRET if it does: ' . implode(', ', $unclassified));
        self::assertSame([], array_values(array_diff($listed, $columns)), 'the lists name columns the table lacks');
        $onBoth = array_intersect(ServedPreferences::SERVED, ServedPreferences::SECRET);
        self::assertSame([], array_values($onBoth), 'a column on both lists');
        self::assertSame(count($listed), count(array_unique($listed)), 'a column listed twice');
        $served = array_intersect(self::KNOWN_SECRETS, ServedPreferences::SERVED);
        self::assertSame([], array_values($served), 'a credential is served');
    }

    public function testEveryColumnPutWritesIsOnTheLists(): void
    {
        self::assertSame([], array_values(array_diff(PreferenceRules::columns(), ServedPreferences::columns())));
    }

    public function testASecretIsServedOnlyAsWhetherOneIsStored(): void
    {
        $row = [];
        foreach (self::usersPrefColumns() as $column) {
            $row[$column] = 'sentinel-' . $column;
        }
        $row['acme_partner_key'] = 'sentinel-acme_partner_key';

        $served = ServedPreferences::serve($row);

        self::assertSame(array_keys($row), array_keys($served), 'every column is answered, none dropped');
        foreach ($row as $column => $value) {
            if (in_array($column, ServedPreferences::SERVED, true)) {
                self::assertSame($value, $served[$column], "$column is served as stored");
            } else {
                self::assertSame(['stored' => true], $served[$column], "$column is answered as its stored state");
            }
        }
        self::assertSame(['stored' => true], $served['acme_partner_key'], 'a column on neither list is a secret');
        $encoded = json_encode($served, JSON_THROW_ON_ERROR);
        foreach ([...ServedPreferences::SECRET, 'acme_partner_key'] as $column) {
            self::assertStringNotContainsString('sentinel-' . $column . '"', $encoded);
        }
    }

    public function testGetPreferencesServesTheRowThroughIt(): void
    {
        $db = $this->createMysqliMock([
            '202_users_pref' => self::row(),
            '202_charts' => ['chart_time_range' => 'hours'],
        ]);

        $served = (new UsersController($db))->getPreferences(1)['data'];

        self::assertSame(ServedPreferences::serve([...self::row(), 'chart_time_range' => 'hours']), $served);
        self::assertSame(['stored' => true], $served['cb_key']);
        self::assertSame(['stored' => false], $served['jvzoo_ipn_secret_key']);
        self::assertSame('hours', $served['chart_time_range']);
        self::assertStringNotContainsString('stored-', json_encode($served, JSON_THROW_ON_ERROR));
    }

    public function testStoredMeansAValueIsThere(): void
    {
        self::assertSame(['stored' => false], ServedPreferences::storedState(null));
        self::assertSame(['stored' => false], ServedPreferences::storedState(''));
        self::assertSame(['stored' => false], ServedPreferences::storedState("  \n"));
        self::assertSame(['stored' => true], ServedPreferences::storedState('0'), '"0" is a value, not none');
        self::assertSame(['stored' => true], ServedPreferences::storedState('k'));
    }

    public function testAGetBodySentBackChangesNothingItHoldsUnchanged(): void
    {
        $served = ServedPreferences::serve(self::row());
        $body = json_decode(json_encode($served, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        [$rest, $errors] = ServedPreferences::withoutEchoes($body, $served);

        self::assertSame([], $errors);
        $secrets = array_intersect(array_keys($rest), ServedPreferences::SECRET);
        self::assertSame([], array_values($secrets), 'no secret is written from its stored state');
        $readOnly = array_diff(array_keys($rest), PreferenceRules::columns());
        self::assertSame([], array_values($readOnly), 'only what the endpoint writes is left');
        self::assertSame(['user_pref_limit' => 50, 'user_daily_email' => '07'], $rest);
    }

    public function testASecretSentAsAStringIsWritten(): void
    {
        $body = ['cb_key' => 'new', 'ipqs_api_key' => ''];

        [$rest, $errors] = ServedPreferences::withoutEchoes($body, ServedPreferences::serve(self::row()));

        self::assertSame([], $errors);
        self::assertSame($body, $rest);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusals(): iterable
    {
        yield 'a stored secret sent back as not stored' => [['cb_key' => ['stored' => false]], 'cb_key'];
        yield 'an unstored secret sent back as stored' => [
            ['zaxaa_api_signature' => ['stored' => true]],
            'zaxaa_api_signature',
        ];
        yield 'a stored state that is not a boolean' => [['cb_key' => ['stored' => 'true']], 'cb_key'];
        yield 'a stored state with more in it' => [['cb_key' => ['stored' => true, 'value' => 'x']], 'cb_key'];
        yield 'an empty object' => [['cb_key' => []], 'cb_key'];
        yield 'a secret PUT does not write, as a string' => [['lpo_site_key' => 'x'], 'lpo_site_key'];
        yield 'a secret PUT does not write, changed' => [
            ['lpo_bridge_config' => ['stored' => false]],
            'lpo_bridge_config',
        ];
        yield 'a read-only column changed' => [['user_id' => 2], 'user_id'];
        yield 'a read-only column sent as null' => [['cb_verified' => null], 'cb_verified'];
    }

    /**
     * @dataProvider refusals
     * @param array<string, mixed> $body
     */
    public function testWhatComesBackChangedIsRefusedByName(array $body, string $field): void
    {
        [$rest, $errors] = ServedPreferences::withoutEchoes($body, ServedPreferences::serve(self::row()));

        self::assertSame([$field], array_keys($errors));
        self::assertSame([], $rest);
        self::assertStringNotContainsString('stored-', $errors[$field], 'a refusal never repeats a secret');
    }

    public function testAnObjectForAColumnThatIsNotASecretIsLeftToItsRule(): void
    {
        $body = ['user_pref_limit' => ['stored' => true]];

        [$rest, $errors] = ServedPreferences::withoutEchoes($body, ServedPreferences::serve(self::row()));

        self::assertSame([], $errors, 'PreferenceRules refuses it, as it refuses any non-choice');
        self::assertSame($body, $rest);
    }

    /** @return array<string, mixed> */
    private static function row(): array
    {
        return [
            'user_id' => 1, 'user_pref_limit' => 50, 'user_pref_show' => null, 'cb_verified' => 1,
            'cb_key' => 'stored-cb', 'jvzoo_ipn_secret_key' => null, 'zaxaa_api_signature' => '',
            'ipqs_api_key' => 'stored-ipqs', 'revcontent_user_id' => null, 'revcontent_user_secret' => 'stored-rc',
            'user_slack_incoming_webhook' => 'https://hooks.slack.com/services/T/B/stored-slack',
            'lpo_site_key' => 'stored-lpo', 'lpo_bridge_config' => '{"ctx_key":"stored-ctx"}',
            'user_daily_email' => '07', 'lpo_status' => 'active',
        ];
    }

    /** @return list<string> */
    private static function usersPrefColumns(): array
    {
        $sql = UserTables::usersPref()->createStatement;
        self::assertMatchesRegularExpression('/CREATE TABLE .*202_users_pref/', $sql);
        $matched = preg_match_all('/^\s*`([a-z0-9_]+)`\s+(?!\()/mi', $sql, $m);
        self::assertIsInt($matched);
        self::assertGreaterThan(0, $matched, 'the users_pref column parse matched nothing');

        return array_map('strtolower', $m[1]);
    }
}
