<?php

declare(strict_types=1);

namespace Tests\Skan;

use Api\V3\Controllers\SkanAppsController;
use Api\V3\Controllers\SkanConversionValuesController;
use Api\V3\Controllers\SkanPostbacksController;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\ValidationException;
use Tests\TestCase;

/**
 * Validation and read-path behaviour of the /skan controllers over the
 * shared mysqli mock. Anything that requires a real INSERT round-trip
 * (insert_id is a C-backed property mocks cannot expose) is exercised by the
 * live-instance flow instead; these tests pin the paths that must reject
 * before any write happens, and the report's conversion-value decode fold.
 */
final class SkanControllersTest extends TestCase
{
    // ─── Conversion-value rules ──────────────────────────────────────

    public function testRuleWithBothFineAndCoarseIsRejected(): void
    {
        $ctrl = new SkanConversionValuesController($this->createMysqliMock(), 1);
        $this->expectException(ValidationException::class);
        $ctrl->create(['fine_value' => 10, 'coarse_value' => 'high', 'event_name' => 'purchase']);
    }

    public function testRuleWithNeitherValueIsRejected(): void
    {
        $ctrl = new SkanConversionValuesController($this->createMysqliMock(), 1);
        $this->expectException(ValidationException::class);
        $ctrl->create(['event_name' => 'purchase']);
    }

    public function testFineValueAbove63IsRejected(): void
    {
        // SKAN fine conversion values are 6 bits; a rule for 64 could never
        // match a postback and would sit there silently decoding nothing.
        $ctrl = new SkanConversionValuesController($this->createMysqliMock(), 1);
        $this->expectException(ValidationException::class);
        $ctrl->create(['fine_value' => 64, 'event_name' => 'purchase']);
    }

    public function testUnknownCoarseValueIsRejected(): void
    {
        $ctrl = new SkanConversionValuesController($this->createMysqliMock(), 1);
        $this->expectException(ValidationException::class);
        $ctrl->create(['coarse_value' => 'huge', 'event_name' => 'purchase']);
    }

    public function testDuplicateRuleIsRejectedWithAReadableConflict(): void
    {
        $db = $this->createMysqliMock([
            'FROM 202_skan_conversion_values WHERE user_id = ? AND app_id = ? AND fine_value = ?' => [
                ['rule_id' => 3],
            ],
        ]);
        $ctrl = new SkanConversionValuesController($db, 1);
        $this->expectException(ConflictException::class);
        $ctrl->create(['fine_value' => 10, 'event_name' => 'purchase']);
    }

    public function testValidRuleClearsValidationBeforeTheInsert(): void
    {
        $ctrl = new SkanConversionValuesController($this->createMysqliMock(), 1);
        try {
            $ctrl->create(['fine_value' => 10, 'event_name' => 'purchase', 'revenue' => 49.99]);
            $this->addToAssertionCount(1); // full mock round-trip succeeded
        } catch (ValidationException | ConflictException $e) {
            $this->fail('A valid rule must clear validation, got: ' . $e->getMessage());
        } catch (\Throwable) {
            // Reaching the INSERT is the point; the mock cannot complete it
            // (insert_id is C-backed), and existing controller tests treat
            // that the same way.
            $this->addToAssertionCount(1);
        }
    }

    public function testNegativeRevenueIsRejected(): void
    {
        $ctrl = new SkanConversionValuesController($this->createMysqliMock(), 1);
        $this->expectException(ValidationException::class);
        $ctrl->create(['fine_value' => 10, 'event_name' => 'purchase', 'revenue' => -1]);
    }

    public function testClearingAKindWithoutItsReplacementNamesTheSwitchSyntax(): void
    {
        // A fine rule; the payload clears fine_value and only adjusts revenue.
        // No replacement kind is supplied, so the row would end up mapping
        // nothing — the error must name the one-request switch, whatever
        // else the payload carried.
        $db = $this->createMysqliMock([
            'FROM 202_skan_conversion_values WHERE rule_id = ?' => [[
                'rule_id' => 5, 'app_id' => 0, 'fine_value' => 10, 'coarse_value' => null,
                'event_name' => 'purchase', 'revenue' => '1.00000', 'user_id' => 1,
            ]],
        ]);
        $ctrl = new SkanConversionValuesController($db, 1);
        try {
            $ctrl->update(5, ['fine_value' => null, 'revenue' => 5]);
            $this->fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('switch kinds', $e->getFieldErrors()['fine_value'] ?? '');
        }
    }

    public function testClearingAnAlreadyNullKindIsNotRejected(): void
    {
        // A coarse rule: clearing fine_value is a no-op, and the rename must
        // reach the UPDATE rather than being refused as a kind-switch.
        $db = $this->createMysqliMock([
            'FROM 202_skan_conversion_values WHERE rule_id = ?' => [[
                'rule_id' => 5, 'app_id' => 0, 'fine_value' => null, 'coarse_value' => 'high',
                'event_name' => 'purchase', 'revenue' => '1.00000', 'user_id' => 1,
            ]],
        ]);
        $ctrl = new SkanConversionValuesController($db, 1);
        try {
            $ctrl->update(5, ['fine_value' => null, 'event_name' => 'renamed']);
            $this->addToAssertionCount(1);
        } catch (ValidationException $e) {
            $this->fail('A no-op clear beside a rename must not be refused: ' . $e->getMessage());
        } catch (\Throwable) {
            // Reaching the UPDATE is the point; the mock cannot complete the
            // round-trip, exactly as in the create tests.
            $this->addToAssertionCount(1);
        }
    }

    // ─── App registry ────────────────────────────────────────────────

    public function testRegisteringAnAlreadyRegisteredAppIdConflicts(): void
    {
        $db = $this->createMysqliMock([
            'FROM 202_skan_apps WHERE app_id = ? LIMIT 1' => [['skan_app_id' => 2]],
        ]);
        $ctrl = new SkanAppsController($db, 1);
        $this->expectException(ConflictException::class);
        $ctrl->create(['app_id' => 525463029, 'app_name' => 'My App']);
    }

    public function testAppRegistrationRequiresAppIdAndName(): void
    {
        $ctrl = new SkanAppsController($this->createMysqliMock(), 1);
        try {
            $ctrl->create([]);
            $this->fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('app_id', $e->getFieldErrors());
            $this->assertArrayHasKey('app_name', $e->getFieldErrors());
        }
    }

    // ─── Postbacks: list ─────────────────────────────────────────────

    public function testListSanitizesReceiverAuthoredStringsAndOmitsTheSignature(): void
    {
        $hostile = "evil\x07net\u{202E}.skadnetwork";
        $db = $this->createMysqliMock([
            'COUNT(*) as total' => [['total' => 1]],
            'ORDER BY postback_id DESC' => [[
                'postback_id' => 5, 'user_id' => 1, 'received_at' => 1700000000,
                'version' => '4.0', 'ad_network_id' => $hostile,
                'transaction_id' => 'tx', 'app_id' => 42, 'source_identifier' => '12',
                'campaign_id' => null, 'conversion_value' => 9,
                'coarse_conversion_value' => null, 'postback_sequence_index' => 0,
                'redownload' => 0, 'did_win' => 1, 'source_app_id' => null,
                'source_domain' => null, 'fidelity_type' => 1, 'country_code' => 'US',
                'signature_valid' => 1, 'remote_ip' => '1.2.3.4',
                'attribution_signature' => 'should-not-be-served-in-lists',
            ]],
        ]);
        $result = (new SkanPostbacksController($db, 1))->list([]);

        $this->assertSame(1, $result['pagination']['total']);
        $row = $result['data'][0];
        $this->assertArrayNotHasKey('attribution_signature', $row);
        $this->assertStringNotContainsString("\x07", $row['ad_network_id']);
        $this->assertStringNotContainsString("\u{202E}", $row['ad_network_id']);
    }

    public function testListRejectsAnUnknownSignatureFilter(): void
    {
        $ctrl = new SkanPostbacksController($this->createMysqliMock(), 1);
        $this->expectException(ValidationException::class);
        $ctrl->list(['signature' => 'probably-fine']);
    }

    // ─── Postbacks: report ───────────────────────────────────────────

    public function testReportRejectsAnUnknownGroupBy(): void
    {
        $ctrl = new SkanPostbacksController($this->createMysqliMock(), 1);
        $this->expectException(ValidationException::class);
        $ctrl->report(['group_by' => 'zodiac-sign']);
    }

    public function testReportDecodesConversionValuesThroughTheRules(): void
    {
        $day = 1700006400; // divisible by 86400 → 2023-11-15 UTC
        $db = $this->createMysqliMock([
            // Per-group aggregates.
            'AS postbacks' => [[
                'grp_day' => $day, 'postbacks' => 6, 'losses' => 1, 'installs' => 4,
                'redownloads' => 1, 'signature_valid_count' => 5,
                'signature_invalid_count' => 0, 'signature_unverified_count' => 1,
            ]],
            // Conversion-value distribution for the same group.
            'cv_app_id' => [
                ['grp_day' => $day, 'cv_app_id' => 525463029, 'conversion_value' => 63, 'coarse_conversion_value' => null, 'cnt' => 2],
                ['grp_day' => $day, 'cv_app_id' => 111, 'conversion_value' => 63, 'coarse_conversion_value' => null, 'cnt' => 1],
                ['grp_day' => $day, 'cv_app_id' => 111, 'conversion_value' => 7, 'coarse_conversion_value' => null, 'cnt' => 1],
                ['grp_day' => $day, 'cv_app_id' => 111, 'conversion_value' => null, 'coarse_conversion_value' => 'high', 'cnt' => 1],
                ['grp_day' => $day, 'cv_app_id' => 111, 'conversion_value' => null, 'coarse_conversion_value' => null, 'cnt' => 1],
            ],
            // The user's decode rules: an app-specific fine rule overriding
            // the default for value 63, plus a default coarse rule.
            'FROM 202_skan_conversion_values WHERE user_id = ?' => [
                ['app_id' => 0, 'fine_value' => 63, 'coarse_value' => null, 'event_name' => 'purchase', 'revenue' => '49.99000'],
                ['app_id' => 525463029, 'fine_value' => 63, 'coarse_value' => null, 'event_name' => 'premium_purchase', 'revenue' => '99.99000'],
                ['app_id' => 0, 'fine_value' => null, 'coarse_value' => 'high', 'event_name' => 'high_value', 'revenue' => '10.00000'],
            ],
        ]);

        $result = (new SkanPostbacksController($db, 1))->report(['group_by' => 'day']);
        $this->assertSame('day', $result['data']['group_by']);
        $this->assertSame('UTC', $result['meta']['timezone']);
        // No signature filter given → the report must default to counting
        // verified rows only, and must say so.
        $this->assertSame('verified-only', $result['meta']['trusted']);
        $this->assertFalse($result['meta']['groups_truncated']);
        $this->assertCount(1, $result['data']['groups']);

        $group = $result['data']['groups'][0];
        $this->assertSame('2023-11-15', $group['date']);
        $this->assertSame(6, $group['postbacks']);
        $this->assertSame(4, $group['installs']);
        $this->assertSame(1, $group['redownloads']);
        $this->assertSame(1, $group['losses']);

        // 5 postbacks carried a value; 4 matched rules; the fine value with
        // no rule stays undecoded (it must NOT fall back to a coarse rule).
        $this->assertSame(5, $group['measurable']);
        $this->assertSame(4, $group['decoded']);
        $this->assertSame(1, $group['undecoded']);
        $this->assertSame(1, $group['null_conversion_values']);

        // 2 × 99.99 (app-specific beats default) + 1 × 49.99 + 1 × 10.00
        $this->assertSame(259.97, $group['decoded_revenue']);
        // events must be an object so a group with no decoded events still
        // JSON-encodes as {} rather than [] (consumers key by event name).
        $this->assertInstanceOf(\stdClass::class, $group['events']);
        $events = (array)$group['events'];
        $this->assertSame(['count' => 2, 'revenue' => 199.98], $events['premium_purchase']);
        $this->assertSame(['count' => 1, 'revenue' => 49.99], $events['purchase']);
        $this->assertSame(['count' => 1, 'revenue' => 10.0], $events['high_value']);
    }

    // ─── Postbacks: verify ───────────────────────────────────────────

    public function testVerifyRequiresABody(): void
    {
        $ctrl = new SkanPostbacksController($this->createMysqliMock(), 1);
        $this->expectException(ValidationException::class);
        $ctrl->verify([]);
    }

    public function testVerifyReportsSignatureStateAndTheSignedMessage(): void
    {
        $ctrl = new SkanPostbacksController($this->createMysqliMock(), 1);

        // Structurally complete 4.0 postback with a wrong signature: the
        // production key must reject it, and the reconstructed message is
        // returned for diffing.
        $result = $ctrl->verify([
            'version' => '4.0',
            'ad-network-id' => 'example123.skadnetwork',
            'source-identifier' => '5239',
            'app-id' => 525463029,
            'transaction-id' => 'tx',
            'redownload' => false,
            'fidelity-type' => 1,
            'did-win' => true,
            'postback-sequence-index' => 0,
            'attribution-signature' => base64_encode('nonsense'),
        ]);
        $this->assertSame('invalid', $result['data']['signature']);
        $this->assertNotNull($result['data']['signed_message_base64']);
        $decoded = base64_decode((string)$result['data']['signed_message_base64'], true);
        $this->assertNotFalse($decoded);
        $this->assertStringContainsString("4.0\u{2063}example123.skadnetwork", $decoded);

        $legacy = $ctrl->verify(['version' => '1.0', 'attribution-signature' => 'AA==']);
        $this->assertSame('unverifiable', $legacy['data']['signature']);
    }
}
