<?php

declare(strict_types=1);

namespace Tests\Attribution\Postbacks;

use Api\V3\Controllers\AttributionAppsController;
use Api\V3\Controllers\AttributionConversionValuesController;
use Api\V3\Controllers\AttributionPostbacksController;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\ValidationException;
use Api\V3\Exception\WriteCommittedException;
use Tests\TestCase;

/**
 * Validation and read-path behaviour of the /attribution postback controllers over the
 * shared mysqli mock. Anything that requires a real INSERT round-trip
 * (insert_id is a C-backed property mocks cannot expose) is exercised by the
 * live-instance flow instead; these tests pin the paths that must reject
 * before any write happens, the report's conversion-value decode fold, and —
 * over the capturing double — the statements the app-registry write paths
 * actually send, and how they report a failure that lands after the write.
 */
final class AttributionControllersTest extends TestCase
{
    use CapturingMysqli;

    // ─── Conversion-value rules ──────────────────────────────────────

    public function testRuleWithBothFineAndCoarseIsRejected(): void
    {
        $ctrl = new AttributionConversionValuesController($this->createMysqliMock(), 1);
        $this->expectException(ValidationException::class);
        $ctrl->create(['fine_value' => 10, 'coarse_value' => 'high', 'event_name' => 'purchase']);
    }

    public function testRuleWithNeitherValueIsRejected(): void
    {
        $ctrl = new AttributionConversionValuesController($this->createMysqliMock(), 1);
        $this->expectException(ValidationException::class);
        $ctrl->create(['event_name' => 'purchase']);
    }

    public function testFineValueAbove63IsRejected(): void
    {
        // SKAN fine conversion values are 6 bits; a rule for 64 could never
        // match a postback and would sit there silently decoding nothing.
        $ctrl = new AttributionConversionValuesController($this->createMysqliMock(), 1);
        $this->expectException(ValidationException::class);
        $ctrl->create(['fine_value' => 64, 'event_name' => 'purchase']);
    }

    public function testUnknownCoarseValueIsRejected(): void
    {
        $ctrl = new AttributionConversionValuesController($this->createMysqliMock(), 1);
        $this->expectException(ValidationException::class);
        $ctrl->create(['coarse_value' => 'huge', 'event_name' => 'purchase']);
    }

    public function testDuplicateRuleIsRejectedWithAReadableConflict(): void
    {
        $db = $this->createMysqliMock([
            'FROM 202_attribution_conversion_values WHERE user_id = ? AND app_id = ? AND fine_value = ?' => [
                ['rule_id' => 3],
            ],
        ]);
        $ctrl = new AttributionConversionValuesController($db, 1);
        $this->expectException(ConflictException::class);
        $ctrl->create(['fine_value' => 10, 'event_name' => 'purchase']);
    }

    public function testValidRuleClearsValidationBeforeTheInsert(): void
    {
        $ctrl = new AttributionConversionValuesController($this->createMysqliMock(), 1);
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

    public function testRevenueAboveWhatTheColumnHoldsIsRejectedAsInputNotAs500(): void
    {
        // revenue is decimal(11,5); without an upper bound the value reached
        // the INSERT and came back as a 500 under strict mode (or was
        // silently clamped, and then decoded as revenue in the report).
        $ctrl = new AttributionConversionValuesController($this->createMysqliMock(), 1);
        $this->expectException(ValidationException::class);
        $ctrl->create(['fine_value' => 10, 'event_name' => 'purchase', 'revenue' => 1000000]);
    }

    /** @dataProvider unusableAppIds */
    public function testAnAppIdTheColumnCannotHoldIsRejected(mixed $appId): void
    {
        // app_id is bigint UNSIGNED, so a negative value is strict-mode
        // error 1264 and surfaces as a 500 for plainly bad input; 0 is the
        // reserved account-wide-default scope for conversion values.
        $ctrl = new AttributionAppsController($this->createMysqliMock(), 1);
        $this->expectException(ValidationException::class);
        $ctrl->create(['app_id' => $appId, 'app_name' => 'Test']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function unusableAppIds(): array
    {
        return ['negative' => [-5], 'reserved zero' => [0]];
    }

    public function testAMistypedPagingValueIsA422NotOneSilentlyClampedRow(): void
    {
        // ?limit=abc used to cast to 0 and clamp to 1, so a caller who
        // mistyped a limit got one row back and read it as "this account has
        // one postback" — the silent coercion every other filter rejects.
        $ctrl = new AttributionPostbacksController($this->createMysqliMock(), 1);
        try {
            $ctrl->list(['limit' => 'abc']);
            $this->fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('limit', $e->getFieldErrors());
        }
    }

    public function testAnOutOfRangePagingNumberStillClamps(): void
    {
        // A range is a documented ceiling, not a typo: 9999 clamps to 500
        // rather than erroring, which is what every paging client expects.
        $ctrl = new AttributionPostbacksController($this->createMysqliMock(), 1);
        $result = $ctrl->list(['limit' => '9999']);
        $this->assertSame(500, $result['pagination']['limit']);
    }

    public function testNegativeRevenueIsRejected(): void
    {
        $ctrl = new AttributionConversionValuesController($this->createMysqliMock(), 1);
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
            'FROM 202_attribution_conversion_values WHERE rule_id = ?' => [[
                'rule_id' => 5, 'app_id' => 0, 'fine_value' => 10, 'coarse_value' => null,
                'event_name' => 'purchase', 'revenue' => '1.00000', 'user_id' => 1,
            ]],
        ]);
        $ctrl = new AttributionConversionValuesController($db, 1);
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
            'FROM 202_attribution_conversion_values WHERE rule_id = ?' => [[
                'rule_id' => 5, 'app_id' => 0, 'fine_value' => null, 'coarse_value' => 'high',
                'event_name' => 'purchase', 'revenue' => '1.00000', 'user_id' => 1,
            ]],
        ]);
        $ctrl = new AttributionConversionValuesController($db, 1);
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
            'FROM 202_attribution_apps WHERE app_id = ? LIMIT 1' => [['attribution_app_id' => 2]],
        ]);
        $ctrl = new AttributionAppsController($db, 1);
        $this->expectException(ConflictException::class);
        $ctrl->create(['app_id' => 525463029, 'app_name' => 'My App']);
    }

    public function testAppRegistrationRequiresAppIdAndName(): void
    {
        $ctrl = new AttributionAppsController($this->createMysqliMock(), 1);
        try {
            $ctrl->create([]);
            $this->fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('app_id', $e->getFieldErrors());
            $this->assertArrayHasKey('app_name', $e->getFieldErrors());
        }
    }

    /** @dataProvider rejectedDevelopmentOptIns */
    public function testTheDevelopmentOptInAcceptsOnlyZeroOrOne(mixed $value): void
    {
        // The opt-in widens what the report trusts, so it is a strict flag:
        // "yes", 2 and true are refused rather than coerced to on.
        $ctrl = new AttributionAppsController($this->createMysqliMock(), 1);
        try {
            $ctrl->create(['app_id' => 525463029, 'app_name' => 'My App', 'accept_development_postbacks' => $value]);
            $this->fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('accept_development_postbacks', $e->getFieldErrors());
        }
    }

    /** @return array<string, array{0: mixed}> */
    public static function rejectedDevelopmentOptIns(): array
    {
        return ['two' => [2], 'negative' => [-1], 'word' => ['yes'], 'bool' => [true]];
    }

    public function testTheDevelopmentOptInClearsValidationAsZeroOrOne(): void
    {
        foreach ([0, 1, '1'] as $value) {
            $ctrl = new AttributionAppsController($this->createMysqliMock(), 1);
            try {
                $ctrl->create(['app_id' => 525463029, 'app_name' => 'My App', 'accept_development_postbacks' => $value]);
                $this->addToAssertionCount(1);
            } catch (ValidationException $e) {
                $this->fail('accept_development_postbacks=' . var_export($value, true) . ' must be accepted: ' . $e->getMessage());
            } catch (\Throwable) {
                // Reaching the INSERT is the point; the mock cannot complete
                // it (insert_id is C-backed), as in the other create tests.
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @dataProvider appIdsTheIntCastWouldRewrite */
    public function testAnAppIdTheIntCastWouldRewriteIsRejected(mixed $appId): void
    {
        // Controller::create() casts app_id to int in validatePayload()
        // before any beforeCreate() guard can look at it, so 1.5 registered
        // app 1, '525463029.9' registered 525463029 and a 20-digit string
        // registered PHP_INT_MAX: a 201 naming an app the caller never sent,
        // holding the global UNIQUE slot. The check has to see the raw value.
        $ctrl = new AttributionAppsController($this->createMysqliMock(), 1);
        try {
            $ctrl->create(['app_id' => $appId, 'app_name' => 'Test']);
            $this->fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('app_id', $e->getFieldErrors());
        }
    }

    /** @return array<string, array{0: mixed}> */
    public static function appIdsTheIntCastWouldRewrite(): array
    {
        return [
            'fraction' => [1.5],
            'exponent string' => ['1e2'],
            'wider than int64' => ['99999999999999999999'],
            'leading space' => [' 1'],
            'float past int64' => [1e20],
            'decimal string' => ['525463029.9'],
        ];
    }

    public function testUpdatingToAnAppIdTheIntCastWouldRewriteIsRejected(): void
    {
        // Same cast, same silent rewrite, on the update path.
        $ctrl = new AttributionAppsController($this->createMysqliMock(), 1);
        try {
            $ctrl->update(7, ['app_id' => '1e2']);
            $this->fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('app_id', $e->getFieldErrors());
        }
    }

    public function testAnAppIdAsAnIntegerOrADigitStringIsStillAccepted(): void
    {
        // JSON bodies send app_id as a number, the Go CLI sends it as a
        // digit string; the raw check must not refuse either.
        foreach ([525463029, '525463029'] as $value) {
            $ctrl = new AttributionAppsController($this->createMysqliMock(), 1);
            try {
                $ctrl->create(['app_id' => $value, 'app_name' => 'My App']);
                $this->addToAssertionCount(1);
            } catch (ValidationException $e) {
                $this->fail('app_id=' . var_export($value, true) . ' must be accepted: ' . $e->getMessage());
            } catch (\Throwable) {
                // Reaching the INSERT is the point; the mock cannot complete
                // it (insert_id is C-backed), as in the other create tests.
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDeletingAnOptedInAppStopsTrustingItsDevelopmentPostbacks(): void
    {
        // The opt-in is a live policy, and deleting the registration is the
        // third way it is toggled. Without a beforeDelete() counterpart the
        // rows it marked trusted stay signature_valid = 1 forever, counted
        // by the default verified-only report, with no registration left to
        // turn them off.
        $db = $this->capturingDb([
            'FROM 202_attribution_apps' => [[
                'attribution_app_id' => 7, 'app_id' => 525463029, 'app_name' => 'My App',
                'notes' => null, 'accept_development_postbacks' => 1,
                'schema_token' => str_repeat('a', 64), 'user_id' => 1,
            ]],
        ]);
        (new AttributionAppsController($db, 1))->delete(7);

        $untrust = array_values(array_filter(
            $this->capturedStatements('UPDATE'),
            static fn(array $s): bool => str_contains($s['sql'], '202_attribution_postbacks')
        ));
        $this->assertCount(1, $untrust, 'the delete must revert the development-trust opt-in exactly once');
        $this->assertStringContainsString('signature_valid = NULL', $untrust[0]['sql']);
        $this->assertStringContainsString("signature_state = 'development'", $untrust[0]['sql']);
        // Scoped to this app and this owner: history a previous owner
        // claimed keeps that owner's decision.
        $this->assertSame('ii', $untrust[0]['types']);
        $this->assertSame([525463029, 1], $untrust[0]['values']);
        // ...and the registration itself is still removed.
        $this->assertNotEmpty($this->capturedStatements('DELETE'));
    }

    public function testARotationWhoseReadBackFailsReportsTheWriteAsCommitted(): void
    {
        // POST /attribution/apps/{id}/schema-token/rotate is stageable, and
        // the UPDATE has already killed the old token by the time the row is
        // read back. Reported as a plain failure the staged-apply seam
        // returns the change to `staged` ("nothing was written") and a
        // re-apply mints a THIRD token, so the token the approver finally
        // reads is not the one in effect (CLAUDE.md #13).
        $db = $this->capturingDb([
            'FROM 202_attribution_apps' => [[
                'attribution_app_id' => 7, 'app_id' => 525463029, 'app_name' => 'My App',
                'notes' => null, 'accept_development_postbacks' => 0,
                'schema_token' => str_repeat('a', 64), 'user_id' => 1,
            ]],
        ]);
        // Only the post-write read-back is forced to fail; every other step
        // of rotateSchemaToken() runs its real code against the double.
        $ctrl = new class ($db, 1) extends AttributionAppsController {
            public int $reads = 0;

            #[\Override]
            public function get(int|string $id): array
            {
                if (++$this->reads > 1) {
                    throw new DatabaseException('Query failed');
                }
                return parent::get($id);
            }
        };

        try {
            $ctrl->rotateSchemaToken(7);
            $this->fail('Expected a WriteCommittedException');
        } catch (WriteCommittedException $e) {
            $this->assertInstanceOf(DatabaseException::class, $e->getPrevious());
            $this->assertSame(2, $ctrl->reads, 'the forced failure must be the read-back, not the ownership check');
        }
        // The rotation itself did go out; that is why a retry is refused.
        $this->assertNotEmpty(array_values(array_filter(
            $this->capturedStatements('UPDATE'),
            static fn(array $s): bool => str_contains($s['sql'], 'schema_token = ?')
        )));
    }

    // ─── Postbacks: list ─────────────────────────────────────────────

    public function testListSanitizesReceiverAuthoredStringsAndOmitsTheSignature(): void
    {
        $hostile = "evil\x07net\u{202E}.skadnetwork";
        $db = $this->createMysqliMock([
            'COUNT(*) as total' => [['total' => 1]],
            'ORDER BY received_at DESC, postback_id DESC' => [[
                'postback_id' => 5, 'user_id' => 1, 'received_at' => 1700000000,
                'protocol' => 'skadnetwork',
                'version' => '4.0', 'ad_network_id' => $hostile,
                'transaction_id' => 'tx', 'app_id' => 42, 'source_identifier' => '12',
                'campaign_id' => null, 'conversion_value' => 9,
                'coarse_conversion_value' => null, 'postback_sequence_index' => 0,
                'conversion_type' => 'download', 'redownload' => 0, 'did_win' => 1,
                'ad_interaction_type' => 'click', 'source_app_id' => null,
                'source_domain' => null, 'marketplace_id' => "com.apple\x07.AppStore",
                'fidelity_type' => 1, 'country_code' => 'US',
                'signature_state' => 'valid', 'signature_valid' => 1,
                'key_id' => "apple-cas-identifier/0\u{202E}", 'remote_ip' => '1.2.3.4',
                'attribution_signature' => 'should-not-be-served-in-lists',
            ]],
        ]);
        $result = (new AttributionPostbacksController($db, 1))->list([]);

        $this->assertSame(1, $result['pagination']['total']);
        $row = $result['data'][0];
        $this->assertArrayNotHasKey('attribution_signature', $row);
        $this->assertStringNotContainsString("\x07", $row['ad_network_id']);
        $this->assertStringNotContainsString("\u{202E}", $row['ad_network_id']);
        // The AdAttributionKit-authored strings are receiver input too: the
        // marketplace id and the signing key id come out of the JWS the
        // poster wrote.
        $this->assertStringNotContainsString("\x07", $row['marketplace_id']);
        $this->assertStringNotContainsString("\u{202E}", $row['key_id']);
    }

    public function testListRejectsAnUnknownSignatureFilter(): void
    {
        $ctrl = new AttributionPostbacksController($this->createMysqliMock(), 1);
        $this->expectException(ValidationException::class);
        $ctrl->list(['signature' => 'probably-fine']);
    }

    public function testTheProtocolFilterAcceptsShorthandsAndNamesTheChoicesOtherwise(): void
    {
        $ctrl = new AttributionPostbacksController($this->createMysqliMock(['COUNT(*) as total' => [['total' => 0]]]), 1);
        foreach (['skan', 'SKAN', 'aak', 'skadnetwork', 'adattributionkit'] as $value) {
            $this->assertSame(0, $ctrl->list(['protocol' => $value])['pagination']['total'], $value);
        }
        try {
            $ctrl->list(['protocol' => 'skadnetworks']);
            $this->fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            $message = $e->getFieldErrors()['protocol'] ?? '';
            $this->assertStringContainsString('skadnetwork, adattributionkit', $message);
            $this->assertStringContainsString('skan, aak', $message);
        }
    }

    /** @dataProvider rejectedEnumFilters */
    public function testEnumFiltersRejectValuesOutsideTheirSets(string $param, string $value): void
    {
        $ctrl = new AttributionPostbacksController($this->createMysqliMock(), 1);
        try {
            $ctrl->list([$param => $value]);
            $this->fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($param, $e->getFieldErrors());
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function rejectedEnumFilters(): array
    {
        return [
            'conversion_type' => ['conversion_type', 'install'],
            'ad_interaction_type' => ['ad_interaction_type', 'tap'],
        ];
    }

    /** @dataProvider integerFiltersTheCastWouldRewrite */
    public function testAnIntegerFilterTheCastWouldRewriteIsRejectedBeforeAnyQuery(string $param, string $value): void
    {
        // The only regression test for the strict integer filters lived in
        // the @group integration suite, so a skipped database job left them
        // with no cover at all. Validation throws before a statement is
        // prepared, so the capturing double proves both halves here: the
        // 422 naming the field, and that nothing reached the server.
        //
        // A digit-only shape is not enough on its own: '9223372036854775808'
        // passed it and the (int) cast then bound 9223372036854775807, so
        // the caller was told "no postbacks for 9223372036854775808" about
        // a number they never sent — the silent rewrite the strict test was
        // added to remove. AttributionAppsController refuses the same class
        // for app_id (CLAUDE.md #5).
        $db = $this->capturingDb();
        try {
            (new AttributionPostbacksController($db, 1))->list([$param => $value]);
            $this->fail("$param=" . var_export($value, true) . ' must be rejected, not rewritten');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($param, $e->getFieldErrors());
        }
        $this->assertSame([], $this->captured, 'the filter must be refused before any statement is prepared');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function integerFiltersTheCastWouldRewrite(): array
    {
        $cases = [];
        foreach (['app_id', 'campaign_id', 'postback_sequence_index', 'fidelity_type', 'time_from', 'time_to', 'limit', 'offset'] as $param) {
            foreach ([
                'fraction' => '1.9',
                'exponent' => '1e0',
                'leading space' => ' 1',
                'trailing space' => '1 ',
                'wider than int64' => '9223372036854775808',
                'far wider than int64' => '99999999999999999999',
                'negative wider than int64' => '-99999999999999999999',
            ] as $label => $value) {
                $cases["$param $label"] = [$param, $value];
            }
        }
        return $cases;
    }

    public function testAnIntegerFilterStillAcceptsWhatTheCastReproducesExactly(): void
    {
        // Leading zeros are not a rewrite ('007' is 7) and the int64
        // endpoints round-trip, so the round-trip check must not refuse
        // them — a guard that also rejects valid input gets removed.
        $ctrl = new AttributionPostbacksController($this->createMysqliMock(['COUNT(*) as total' => [['total' => 0]]]), 1);
        foreach (['525463029', '007', '0', '-0', '-1', (string)PHP_INT_MAX, (string)PHP_INT_MIN] as $value) {
            $this->assertSame(0, $ctrl->list(['app_id' => $value])['pagination']['total'], $value);
        }
    }

    public function testTheSignatureFilterAcceptsDevelopment(): void
    {
        $ctrl = new AttributionPostbacksController($this->createMysqliMock(['COUNT(*) as total' => [['total' => 0]]]), 1);
        $this->assertSame(0, $ctrl->list(['signature' => 'development'])['pagination']['total']);
    }

    public function testTheReportAcceptsTheProtocolAndConversionTypeModes(): void
    {
        $metrics = [
            'postbacks' => 1, 'losses' => 0, 'installs' => 0, 'redownloads' => 0, 'reengagements' => 1,
            'signature_valid_count' => 1, 'signature_invalid_count' => 0,
            'signature_unverified_count' => 0, 'signature_development_count' => 1,
        ];
        $db = $this->createMysqliMock([
            'AS postbacks' => [
                ['grp_protocol' => 'adattributionkit', 'grp_conversion_type' => 're-engagement'] + $metrics,
            ],
        ]);
        $ctrl = new AttributionPostbacksController($db, 1);

        $group = $ctrl->report(['group_by' => 'protocol'])['data']['groups'][0];
        $this->assertSame('adattributionkit', $group['protocol']);
        $this->assertSame(1, $group['reengagements']);
        $this->assertSame(1, $group['signature_development_count']);

        $group = $ctrl->report(['group_by' => 'conversion-type'])['data']['groups'][0];
        $this->assertSame('re-engagement', $group['conversion_type']);
    }

    // ─── Postbacks: report ───────────────────────────────────────────

    public function testReportRejectsAnUnknownGroupBy(): void
    {
        $ctrl = new AttributionPostbacksController($this->createMysqliMock(), 1);
        $this->expectException(ValidationException::class);
        $ctrl->report(['group_by' => 'zodiac-sign']);
    }

    public function testTheReportIdentityPinsItsFieldBoundaries(): void
    {
        // The identity joined protocol, ad_network_id, transaction_id and
        // the window with a plain '|', and neither protocol restricts the
        // characters in the two attacker-authored ones: ('acme|A9F3',
        // 'B7C2') and ('acme', 'A9F3|B7C2') are two different postbacks,
        // stored as two rows, that produced one identity string and counted
        // once. The behaviour is proved against a real server in
        // AttributionReportIntegrationTest; this pins the SQL shape so the
        // guard survives a skipped database job.
        $db = $this->capturingDb();
        (new AttributionPostbacksController($db, 1))->report(['group_by' => 'day', 'time_from' => 0]);

        $usingIdentity = array_values(array_filter(
            $this->capturedStatements('SELECT'),
            static fn(array $s): bool => str_contains($s['sql'], 'COUNT(DISTINCT') || str_contains($s['sql'], 'MIN(postback_id)')
        ));
        $this->assertCount(2, $usingIdentity, 'the aggregate and the first-copy decode subquery both key on the identity');
        foreach ($usingIdentity as $statement) {
            foreach (['protocol', 'ad_network_id', 'transaction_id'] as $field) {
                $this->assertStringContainsString(
                    "CONCAT(LENGTH($field), ':', $field)",
                    $statement['sql'],
                    "$field must be length-prefixed so a separator inside it cannot shift a field boundary"
                );
            }
            // ...and compared as bytes: the columns collate
            // utf8mb4_general_ci, which folds 'ACME.skadnetwork' into
            // 'acme.skadnetwork' — the same two-rows-counted-as-one by a
            // different route.
            $this->assertStringContainsString('AS BINARY)', $statement['sql']);
        }
    }

    public function testADayReportShortOfAFullPageIsRecomputedWithoutTheInternalWindow(): void
    {
        // A day report with no time_from tries a window bounded to the
        // newest limit + 1 days first, because aggregating a tenant's whole
        // retained history to throw all but a page away got slower every
        // month. A SHORT page proves nothing about older days — a tenant
        // with 6 populated days spread over 700 came back with 2 — so the
        // bounded attempt is thrown away and the query re-run unbounded.
        $db = $this->capturingDb();
        $report = (new AttributionPostbacksController($db, 1))->report([]);

        $aggregates = array_values(array_filter(
            $this->capturedStatements('SELECT'),
            static fn(array $s): bool => str_contains($s['sql'], 'AS postbacks')
        ));
        $this->assertCount(2, $aggregates, 'a short page cannot stand as the answer');
        // user_id, the bounded time_from, the LIMIT.
        $this->assertStringContainsString('received_at >= ?', $aggregates[0]['sql']);
        $this->assertSame('iii', $aggregates[0]['types']);
        // The re-run carries the caller's own filters only: user_id, LIMIT.
        $this->assertStringNotContainsString('received_at >= ?', $aggregates[1]['sql']);
        $this->assertSame('ii', $aggregates[1]['types']);
        // The result is identical to the unbounded query either way, so
        // there is no window to disclose to the caller.
        $this->assertArrayNotHasKey('time_from_defaulted', $report['meta']);
        $this->assertStringNotContainsString('time_from defaulted', $report['meta']['notes']);
    }

    public function testADayReportThatFillsThePageIsNotRecomputed(): void
    {
        // The other half: limit + 1 groups came back from inside the window,
        // the query orders by day DESC, and every day the bound excluded is
        // older than every day it kept — so those ARE the newest limit + 1
        // groups overall and the second query would be wasted work. This is
        // the case the bound was measured on.
        $metrics = [
            'postbacks' => 1, 'losses' => 0, 'installs' => 1, 'redownloads' => 0, 'reengagements' => 0,
            'signature_valid_count' => 1, 'signature_invalid_count' => 0,
            'signature_unverified_count' => 0, 'signature_development_count' => 0,
        ];
        $day = intdiv(time(), 86400) * 86400;
        $db = $this->capturingDb(['AS postbacks' => [
            ['grp_day' => $day] + $metrics,
            ['grp_day' => $day - 86400] + $metrics,
            ['grp_day' => $day - 2 * 86400] + $metrics,
        ]]);
        $report = (new AttributionPostbacksController($db, 1))->report(['limit' => 2]);

        $aggregates = array_values(array_filter(
            $this->capturedStatements('SELECT'),
            static fn(array $s): bool => str_contains($s['sql'], 'AS postbacks')
        ));
        $this->assertCount(1, $aggregates, 'a full page is provably the whole answer; the second query is skipped');
        $this->assertTrue($report['meta']['groups_truncated']);
        $this->assertCount(2, $report['data']['groups']);
    }

    public function testReportDecodesConversionValuesThroughTheRules(): void
    {
        $day = 1700006400; // divisible by 86400 → 2023-11-15 UTC
        $db = $this->createMysqliMock([
            // Per-group aggregates.
            'AS postbacks' => [[
                'grp_day' => $day, 'postbacks' => 6, 'losses' => 1, 'installs' => 4,
                'redownloads' => 1, 'reengagements' => 0, 'signature_valid_count' => 5,
                'signature_invalid_count' => 0, 'signature_unverified_count' => 1,
                'signature_development_count' => 0,
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
            'FROM 202_attribution_conversion_values WHERE user_id = ?' => [
                ['app_id' => 0, 'fine_value' => 63, 'coarse_value' => null, 'event_name' => 'purchase', 'revenue' => '49.99000'],
                ['app_id' => 525463029, 'fine_value' => 63, 'coarse_value' => null, 'event_name' => 'premium_purchase', 'revenue' => '99.99000'],
                ['app_id' => 0, 'fine_value' => null, 'coarse_value' => 'high', 'event_name' => 'high_value', 'revenue' => '10.00000'],
            ],
        ]);

        $result = (new AttributionPostbacksController($db, 1))->report(['group_by' => 'day']);
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
        $ctrl = new AttributionPostbacksController($this->createMysqliMock(), 1);
        $this->expectException(ValidationException::class);
        $ctrl->verify([]);
    }

    public function testVerifyReportsSignatureStateAndTheSignedMessage(): void
    {
        $ctrl = new AttributionPostbacksController($this->createMysqliMock(), 1);

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
        $this->assertSame('skadnetwork', $result['data']['protocol']);
        $this->assertSame('invalid', $result['data']['signature']);
        $this->assertNotNull($result['data']['signed_message_base64']);
        $decoded = base64_decode((string)$result['data']['signed_message_base64'], true);
        $this->assertNotFalse($decoded);
        $this->assertStringContainsString("4.0\u{2063}example123.skadnetwork", $decoded);

        $legacy = $ctrl->verify(['version' => '1.0', 'attribution-signature' => 'AA==']);
        $this->assertSame('unverifiable', $legacy['data']['signature']);
    }

    public function testVerifyDetectsAnAdAttributionKitBodyByItsJws(): void
    {
        $ctrl = new AttributionPostbacksController($this->createMysqliMock(), 1);

        // The JWS alone is enough: the unsigned envelope fields play no part
        // in the verdict, so an operator can paste just the jws-string.
        $result = $ctrl->verify(['jws-string' => AdAttributionKitFixtures::EXAMPLE_JWS]);
        $this->assertSame('adattributionkit', $result['data']['protocol']);
        $this->assertSame('development', $result['data']['signature']);
        $this->assertSame(AdAttributionKitFixtures::EXAMPLE_KEY_ID, $result['data']['key_id']);
        $this->assertSame('ES256', $result['data']['header']['alg']);
        $this->assertSame(AdAttributionKitFixtures::EXAMPLE_POSTBACK_ID, $result['data']['payload']['postback-identifier']);
        $this->assertContains(AdAttributionKitFixtures::EXAMPLE_KEY_ID, $result['data']['development_key_ids']);
        $this->assertContains('apple-cas-identifier/0', $result['data']['known_key_ids']);

        try {
            $ctrl->verify(['jws-string' => 'not.a']);
            $this->fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('jws-string', $e->getFieldErrors());
        }
    }
}
