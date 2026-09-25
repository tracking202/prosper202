<?php

declare(strict_types=1);

namespace Tests\Apps\Apple;

use Api\V3\Controllers\AppRegistrationsController;
use Api\V3\Controllers\AppSkanEncodingsController;
use Api\V3\Controllers\AppPostbacksController;
use Api\V3\Apps\Apple\AdAttributionKitProtocol;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\ValidationException;
use Api\V3\Exception\WriteCommittedException;
use Tests\TestCase;

/**
 * Validation and read-path behaviour of the /apps controllers over the
 * shared mysqli mock. Anything that requires a real INSERT round-trip
 * (insert_id is a C-backed property mocks cannot expose) is exercised by the
 * live-instance flow instead; these tests pin the paths that must reject
 * before any write happens, the report's conversion-value decode fold, and —
 * over the capturing double — the statements the app-registry write paths
 * actually send, and how they report a failure that lands after the write.
 */
final class AppControllersTest extends TestCase
{
    use CapturingMysqli;

    // ─── Conversion-value rules ──────────────────────────────────────

    /** Goal 9: a live account goal that is a plain event goal, which any encoding scope may name. */
    private const PLAIN_ACCOUNT_GOAL = ['FROM 202_goals g' => [['scope' => 'account', 'scope_id' => 0, 'archived_at' => null,
        'definition' => '{"name":"purchase","trigger":{"event":"purchase","where":[]},"threshold":{"count":1},"after":[],"within":null,"repeat":{"mode":"once"},"value":{"type":"none"}}']]];

    public function testRuleWithBothFineAndCoarseIsRejected(): void
    {
        $ctrl = new AppSkanEncodingsController($this->createMysqliMock(), 1);
        $this->expectException(ValidationException::class);
        $ctrl->create(['fine_value' => 10, 'coarse_value' => 'high', 'goal_id' => 9]);
    }

    public function testRuleWithNeitherValueIsRejected(): void
    {
        $ctrl = new AppSkanEncodingsController($this->createMysqliMock(), 1);
        $this->expectException(ValidationException::class);
        $ctrl->create(['goal_id' => 9]);
    }

    public function testFineValueAbove63IsRejected(): void
    {
        // SKAN fine conversion values are 6 bits; a rule for 64 could never
        // match a postback and would sit there silently decoding nothing.
        $ctrl = new AppSkanEncodingsController($this->createMysqliMock(), 1);
        $this->expectException(ValidationException::class);
        $ctrl->create(['fine_value' => 64, 'goal_id' => 9]);
    }

    public function testUnknownCoarseValueIsRejected(): void
    {
        $ctrl = new AppSkanEncodingsController($this->createMysqliMock(), 1);
        $this->expectException(ValidationException::class);
        $ctrl->create(['coarse_value' => 'huge', 'goal_id' => 9]);
    }

    public function testDuplicateRuleIsRejectedWithAReadableConflict(): void
    {
        $db = $this->createMysqliMock([
            'FROM 202_app_skan_encodings WHERE user_id = ? AND registration_id = ? AND fine_value = ?' => [
                ['encoding_id' => 3],
            ],
        ] + self::PLAIN_ACCOUNT_GOAL);
        $ctrl = new AppSkanEncodingsController($db, 1);
        $this->expectException(ConflictException::class);
        $ctrl->create(['fine_value' => 10, 'goal_id' => 9]);
    }

    public function testValidRuleClearsValidationBeforeTheInsert(): void
    {
        $ctrl = new AppSkanEncodingsController($this->createMysqliMock(self::PLAIN_ACCOUNT_GOAL), 1);
        try {
            $ctrl->create(['fine_value' => 10, 'goal_id' => 9, 'revenue_override' => 49.99]);
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
        // revenue_override is decimal(11,5); without an upper bound the value reached
        // the INSERT and came back as a 500 under strict mode (or was
        // silently clamped, and then decoded as revenue in the report).
        $ctrl = new AppSkanEncodingsController($this->createMysqliMock(), 1);
        $this->expectException(ValidationException::class);
        $ctrl->create(['fine_value' => 10, 'goal_id' => 9, 'revenue_override' => 1000000]);
    }

    public function testAMistypedPagingValueIsA422NotOneSilentlyClampedRow(): void
    {
        // ?limit=abc used to cast to 0 and clamp to 1, so a caller who
        // mistyped a limit got one row back and read it as "this account has
        // one postback" — the silent coercion every other filter rejects.
        $ctrl = new AppPostbacksController($this->createMysqliMock(), 1);
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
        $ctrl = new AppPostbacksController($this->createMysqliMock(), 1);
        $result = $ctrl->list(['limit' => '9999']);
        $this->assertSame(500, $result['pagination']['limit']);
    }

    public function testNegativeRevenueIsRejected(): void
    {
        $ctrl = new AppSkanEncodingsController($this->createMysqliMock(), 1);
        $this->expectException(ValidationException::class);
        $ctrl->create(['fine_value' => 10, 'goal_id' => 9, 'revenue_override' => -1]);
    }

    public function testClearingAKindWithoutItsReplacementNamesTheSwitchSyntax(): void
    {
        // A fine rule; the payload clears fine_value and only adjusts revenue.
        // No replacement kind is supplied, so the row would end up mapping
        // nothing — the error must name the one-request switch, whatever
        // else the payload carried.
        $db = $this->createMysqliMock([
            'FROM 202_app_skan_encodings WHERE encoding_id = ?' => [[
                'encoding_id' => 5, 'registration_id' => 0, 'fine_value' => 10, 'coarse_value' => null,
                'goal_id' => 9, 'revenue_override' => '1.00000', 'user_id' => 1,
            ]],
        ]);
        $ctrl = new AppSkanEncodingsController($db, 1);
        try {
            $ctrl->update(5, ['fine_value' => null, 'revenue_override' => 5]);
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
            'FROM 202_app_skan_encodings WHERE encoding_id = ?' => [[
                'encoding_id' => 5, 'registration_id' => 0, 'fine_value' => null, 'coarse_value' => 'high',
                'goal_id' => 9, 'revenue_override' => '1.00000', 'user_id' => 1,
            ]],
        ] + self::PLAIN_ACCOUNT_GOAL);
        $ctrl = new AppSkanEncodingsController($db, 1);
        try {
            $ctrl->update(5, ['fine_value' => null, 'goal_id' => 9]);
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

    /** A registration row as the controller's get() reads it back. */
    private const REGISTRATION = [
        'registration_id' => 7, 'user_id' => 1, 'platform' => 'ios', 'app_key' => '525463029',
        'app_name' => 'My App', 'notes' => null, 'accept_test_signals' => 1,
        'app_token' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ];

    public function testRegisteringAnAlreadyRegisteredAppConflicts(): void
    {
        $db = $this->createMysqliMock([
            'FROM 202_app_registrations WHERE platform = ? AND app_key = ? LIMIT 1' => [['registration_id' => 2]],
        ]);
        $ctrl = new AppRegistrationsController($db, 1);
        $this->expectException(ConflictException::class);
        $ctrl->create(['app_key' => '525463029', 'app_name' => 'My App']);
    }

    public function testAppRegistrationRequiresAnAppAndAName(): void
    {
        $ctrl = new AppRegistrationsController($this->createMysqliMock(), 1);
        try {
            $ctrl->create([]);
            $this->fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('app_key', $e->getFieldErrors(), 'no app named: the key (or a store link) is asked for');
        }
        try {
            $ctrl->create(['app_key' => '525463029']);
            $this->fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('app_name', $e->getFieldErrors());
        }
    }

    /** @dataProvider rejectedTestSignalPolicies */
    public function testTheTestSignalPolicyAcceptsOnlyZeroOrOne(mixed $value): void
    {
        // The policy widens what the report trusts, so it is a strict flag:
        // "yes", 2 and true are refused rather than coerced to on.
        $ctrl = new AppRegistrationsController($this->createMysqliMock(), 1);
        try {
            $ctrl->create(['app_key' => '525463029', 'app_name' => 'My App', 'accept_test_signals' => $value]);
            $this->fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('accept_test_signals', $e->getFieldErrors());
        }
    }

    /** @return array<string, array{0: mixed}> */
    public static function rejectedTestSignalPolicies(): array
    {
        return ['two' => [2], 'negative' => [-1], 'word' => ['yes'], 'bool' => [true]];
    }

    public function testTheTestSignalPolicyClearsValidationAsZeroOrOne(): void
    {
        foreach ([0, 1, '1'] as $value) {
            $ctrl = new AppRegistrationsController($this->createMysqliMock(), 1);
            try {
                $ctrl->create(['app_key' => '525463029', 'app_name' => 'My App', 'accept_test_signals' => $value]);
                $this->addToAssertionCount(1);
            } catch (ValidationException $e) {
                $this->fail('accept_test_signals=' . var_export($value, true) . ' must be accepted: ' . $e->getMessage());
            } catch (\Throwable) {
                // Reaching the INSERT is the point; the mock cannot complete
                // it (insert_id is C-backed), as in the other create tests.
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @dataProvider keysTheIntCastWouldRewrite */
    public function testAnAppKeyTheIntCastWouldRewriteIsRejectedBeforeAnyStatement(mixed $appKey): void
    {
        // Controller::create() would cast before any hook looked, so 1.5
        // registered app 1 and a 20-digit string PHP_INT_MAX: a 201 naming an
        // app the caller never sent, holding the global UNIQUE slot
        // (CLAUDE.md #18). AppIdentity reads the raw value, before anything
        // is prepared.
        $db = $this->capturingDb();
        try {
            (new AppRegistrationsController($db, 1))->create(['platform' => 'ios', 'app_key' => $appKey, 'app_name' => 'Test']);
            $this->fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('app_key', $e->getFieldErrors());
        }
        $this->assertSame([], $this->captured, 'the refusal lands before anything is prepared');
    }

    /** @return array<string, array{0: mixed}> */
    public static function keysTheIntCastWouldRewrite(): array
    {
        return [
            'fraction' => [1.5],
            'exponent string' => ['1e2'],
            'wider than int64' => ['99999999999999999999'],
            'leading space' => [' 1'],
            'float past int64' => [1e20],
            'decimal string' => ['525463029.9'],
            'negative' => [-5],
            'zero' => [0],
            'leading zero' => ['0525463029'],
        ];
    }

    /**
     * The app a registration names is fixed. Naming a different one on
     * update is refused by name, before any write; naming the same one again
     * is accepted and writes nothing to the identity columns.
     */
    public function testUpdatingARegistrationToADifferentAppIsRefused(): void
    {
        foreach ([
            ['app_key' => '525463030'],
            ['app_key' => '1e2'],
            ['platform' => 'android'],
            ['store_link' => 'https://play.google.com/store/apps/details?id=com.example.app'],
        ] as $payload) {
            $db = $this->capturingDb(['FROM 202_app_registrations WHERE registration_id = ?' => [self::REGISTRATION]]);
            try {
                (new AppRegistrationsController($db, 1))->update(7, $payload + ['app_name' => 'Renamed']);
                $this->fail('a different app was accepted: ' . json_encode($payload));
            } catch (ValidationException $e) {
                $this->assertNotSame([], $e->getFieldErrors(), json_encode($payload));
            }
            $this->assertSame([], $this->capturedStatements('UPDATE'), 'nothing written for ' . json_encode($payload));
            $this->captured = [];
        }
    }

    public function testRepeatingTheSameAppOnUpdateIsAccepted(): void
    {
        $db = $this->capturingDb(['FROM 202_app_registrations WHERE registration_id = ?' => [self::REGISTRATION]]);
        (new AppRegistrationsController($db, 1))->update(7, [
            'platform' => 'iOS',
            'app_key' => 525463029,
            'app_name' => 'Renamed',
        ]);
        $updates = array_values(array_filter(
            $this->capturedStatements('UPDATE'),
            static fn(array $s): bool => str_contains($s['sql'], 'UPDATE 202_app_registrations SET')
        ));
        $this->assertCount(1, $updates);
        $this->assertStringNotContainsString('app_key', $updates[0]['sql'], 'the identity is never rewritten');
        $this->assertStringNotContainsString('platform', $updates[0]['sql']);
        $this->assertContains('Renamed', $updates[0]['values']);
    }

    public function testDeletingARegistrationWithdrawsItsTestSignalTrustUnlinksAndCascadesAtomically(): void
    {
        // The policy is live, and deleting the registration is the third way
        // it is toggled. Without this the rows it marked trusted stay
        // trusted = 1 forever, counted by the default report, with no
        // registration left to turn them off.
        $db = $this->capturingDb(['FROM 202_app_registrations WHERE registration_id = ?' => [self::REGISTRATION]]);
        $db->expects($this->once())->method('begin_transaction')->willReturn(true);
        $db->expects($this->once())->method('commit')->willReturn(true);
        (new AppRegistrationsController($db, 1))->delete(7);

        $writes = array_values(array_filter(
            $this->captured,
            static fn(array $s): bool => preg_match('/^(UPDATE|DELETE)/', ltrim($s['sql'])) === 1
        ));
        $sql = array_map(static fn(array $s): string => $s['sql'], $writes);
        $this->assertCount(6, $writes, implode("\n", $sql));
        $this->assertStringContainsString('UPDATE 202_app_postbacks SET trusted = NULL', $sql[0]);
        $this->assertStringContainsString('signature_state = ?', $sql[0]);
        $this->assertSame([7, 1, 'development'], $writes[0]['values'], 'this registration, this owner, test signals only');
        $this->assertStringContainsString('UPDATE 202_app_postbacks SET registration_id = NULL', $sql[1]);
        $this->assertStringContainsString('DELETE FROM 202_app_skan_encodings', $sql[2]);
        // Its campaigns are unlinked, or re-registering the app (a new id)
        // would find them linked to a registration that no longer exists.
        $this->assertStringContainsString('UPDATE 202_aff_campaigns SET app_registration_id = NULL WHERE app_registration_id = ? AND user_id = ?', $sql[3]);
        $this->assertSame([7, 1], $writes[3]['values'], 'this registration\'s campaigns, this owner');
        // The app's goals are archived with it, their history kept.
        $this->assertStringContainsString('UPDATE 202_goals SET archived_at = ?', $sql[4]);
        $this->assertSame([7, 1], array_slice($writes[4]['values'], 2), 'this registration\'s goals, this owner');
        $this->assertStringContainsString('DELETE FROM 202_app_registrations', $sql[5]);
    }

    public function testTheStoredPlatformIsCanonicalWhateverCaseWasSent(): void
    {
        // AppIdentity compares case-insensitively, so 'iOS' is accepted. If
        // the write path then stored 'iOS' verbatim, the row would not match
        // a later `WHERE platform = 'ios'`. Reading the bound value rather
        // than mocking the normaliser is the point.
        $db = $this->capturingDb();
        try {
            (new AppRegistrationsController($db, 1))->create([
                'app_key' => '525463029',
                'app_name' => 'Case Test',
                'platform' => '  iOS  ',
            ]);
        } catch (\Throwable) {
            // The double cannot expose insert_id, so create() fails after the
            // INSERT. The statement it sent was still captured.
        }

        $inserts = array_values(array_filter(
            $this->capturedStatements('INSERT'),
            static fn(array $s): bool => str_contains($s['sql'], '202_app_registrations')
        ));
        $this->assertCount(1, $inserts, 'the registration INSERT was captured');
        $this->assertContains('ios', $inserts[0]['values'], 'the canonical spelling is what is bound');
        $this->assertNotContains('  iOS  ', $inserts[0]['values'], 'the raw spelling is not');
        $this->assertContains('525463029', $inserts[0]['values']);
    }

    public function testAStoreLinkIsReadForTheAppItNamesAndNeverStored(): void
    {
        $db = $this->capturingDb();
        try {
            (new AppRegistrationsController($db, 1))->create([
                'store_link' => 'https://play.google.com/store/apps/details?id=com.Example.app',
                'app_name' => 'Android App',
            ]);
        } catch (\Throwable) {
            // insert_id, as above.
        }
        $inserts = $this->capturedStatements('INSERT');
        $this->assertCount(1, $inserts);
        $this->assertStringNotContainsString('store_link', $inserts[0]['sql']);
        $this->assertContains('android', $inserts[0]['values']);
        $this->assertContains('com.Example.app', $inserts[0]['values'], 'case is part of an Android application id');
    }

    public function testAnUnknownPlatformIsRefusedBeforeAnyStatement(): void
    {
        $db = $this->capturingDb();
        try {
            (new AppRegistrationsController($db, 1))->create([
                'app_key' => '525463029',
                'app_name' => 'Windows Test',
                'platform' => 'windows',
            ]);
            $this->fail('windows was accepted');
        } catch (ValidationException $e) {
            $this->assertSame('Must be one of: ios, android', $e->getFieldErrors()['platform'] ?? '');
        }
        $this->assertSame([], $this->captured, 'the refusal must land before anything is prepared');
    }

    public function testARotationWhoseReadBackFailsReportsTheWriteAsCommitted(): void
    {
        // POST /apps/{id}/app-token/rotate is stageable, and the UPDATE has
        // already killed the old token by the time the row is read back.
        // Reported as a plain failure the staged-apply seam returns the
        // change to `staged` ("nothing was written") and a re-apply mints a
        // THIRD token (CLAUDE.md #13).
        $db = $this->capturingDb([
            'FROM 202_app_registrations' => [self::REGISTRATION],
        ]);
        // Only the post-write read-back is forced to fail; every other step
        // of rotateAppToken() runs its real code against the double.
        $ctrl = new class ($db, 1) extends AppRegistrationsController {
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
            $ctrl->rotateAppToken(7);
            $this->fail('Expected a WriteCommittedException');
        } catch (WriteCommittedException $e) {
            $this->assertInstanceOf(DatabaseException::class, $e->getPrevious());
            $this->assertSame(2, $ctrl->reads, 'the forced failure must be the read-back, not the ownership check');
        }
        // The rotation itself did go out; that is why a retry is refused.
        $this->assertNotEmpty(array_values(array_filter(
            $this->capturedStatements('UPDATE'),
            static fn(array $s): bool => str_contains($s['sql'], 'app_token = ?')
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
                'signature_state' => 'valid', 'trusted' => 1, 'registration_id' => 7,
                'key_id' => "apple-cas-identifier/0\u{202E}", 'remote_ip' => '1.2.3.4',
                'attribution_signature' => 'should-not-be-served-in-lists',
            ]],
        ]);
        $result = (new AppPostbacksController($db, 1))->list([]);

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
        $ctrl = new AppPostbacksController($this->createMysqliMock(), 1);
        $this->expectException(ValidationException::class);
        $ctrl->list(['signature' => 'probably-fine']);
    }

    public function testTheProtocolFilterAcceptsShorthandsAndNamesTheChoicesOtherwise(): void
    {
        $ctrl = new AppPostbacksController($this->createMysqliMock(['COUNT(*) as total' => [['total' => 0]]]), 1);
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
        $ctrl = new AppPostbacksController($this->createMysqliMock(), 1);
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
        // added to remove. AppRegistrationsController refuses the same class
        // for app_id (CLAUDE.md #5).
        $db = $this->capturingDb();
        try {
            (new AppPostbacksController($db, 1))->list([$param => $value]);
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
        $ctrl = new AppPostbacksController($this->createMysqliMock(['COUNT(*) as total' => [['total' => 0]]]), 1);
        foreach (['525463029', '007', '0', '-0', '-1', (string)PHP_INT_MAX, (string)PHP_INT_MIN] as $value) {
            $this->assertSame(0, $ctrl->list(['app_id' => $value])['pagination']['total'], $value);
        }
    }

    public function testTheSignatureFilterAcceptsDevelopment(): void
    {
        $ctrl = new AppPostbacksController($this->createMysqliMock(['COUNT(*) as total' => [['total' => 0]]]), 1);
        $this->assertSame(0, $ctrl->list(['signature' => 'development'])['pagination']['total']);
    }

    public function testTheReportAcceptsTheProtocolAndConversionTypeModes(): void
    {
        $metrics = [
            'postbacks' => 1, 'losses' => 0, 'installs' => 0, 'redownloads' => 0, 'reengagements' => 1,
            'trusted_count' => 1, 'refuted_count' => 0,
            'unvouched_count' => 0, 'test_count' => 1,
        ];
        $db = $this->createMysqliMock([
            'AS postbacks' => [
                ['grp_protocol' => 'adattributionkit', 'grp_conversion_type' => 're-engagement'] + $metrics,
            ],
        ]);
        $ctrl = new AppPostbacksController($db, 1);

        $group = $ctrl->report(['group_by' => 'protocol'])['data']['groups'][0];
        $this->assertSame('adattributionkit', $group['protocol']);
        $this->assertSame(1, $group['reengagements']);
        $this->assertSame(1, $group['test_count']);

        $group = $ctrl->report(['group_by' => 'conversion-type'])['data']['groups'][0];
        $this->assertSame('re-engagement', $group['conversion_type']);
    }

    // ─── Postbacks: report ───────────────────────────────────────────

    public function testReportRejectsAnUnknownGroupBy(): void
    {
        $ctrl = new AppPostbacksController($this->createMysqliMock(), 1);
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
        // AppReportIntegrationTest; this pins the SQL shape so the
        // guard survives a skipped database job.
        $db = $this->capturingDb();
        (new AppPostbacksController($db, 1))->report(['group_by' => 'day', 'time_from' => 0]);

        $usingIdentity = array_values(array_filter(
            $this->capturedStatements('SELECT'),
            static fn(array $s): bool => str_contains($s['sql'], 'COUNT(DISTINCT') || str_contains($s['sql'], 'MIN(postback_id)')
        ));
        $this->assertCount(
            3,
            $usingIdentity,
            'the grouped aggregate, the ungrouped totals beside it and the first-copy decode subquery all key on the identity'
        );
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
        $report = (new AppPostbacksController($db, 1))->report([]);

        // GROUP BY, not just 'AS postbacks': the ungrouped totals query
        // selects the same metric columns, and counting it here would hide
        // whichever grouped query stopped being issued.
        $aggregates = $this->groupedAggregates();
        $this->assertCount(2, $aggregates, 'a short page cannot stand as the answer');
        $this->assertCount(1, $this->ungroupedTotals(), 'the totals are read once, whichever attempt wins');
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
            'trusted_count' => 1, 'refuted_count' => 0,
            'unvouched_count' => 0, 'test_count' => 0,
        ];
        $day = intdiv(time(), 86400) * 86400;
        $db = $this->capturingDb(['AS postbacks' => [
            ['grp_day' => $day] + $metrics,
            ['grp_day' => $day - 86400] + $metrics,
            ['grp_day' => $day - 2 * 86400] + $metrics,
        ]]);
        $report = (new AppPostbacksController($db, 1))->report(['limit' => 2]);

        $aggregates = $this->groupedAggregates();
        $this->assertCount(1, $aggregates, 'a full page is provably the whole answer; the second query is skipped');

        // The bound is an optimisation for choosing WHICH days to return. The
        // totals are the whole window by definition, so they must not inherit
        // it — an established install whose newest limit + 1 days are busy
        // would otherwise be told its lifetime totals were those few days.
        $totals = $this->ungroupedTotals();
        $this->assertCount(1, $totals, 'the totals are read once either way');
        $this->assertStringContainsString('received_at >= ?', $aggregates[0]['sql'], 'the GROUPED query is bounded');
        $this->assertStringNotContainsString(
            'received_at >= ?',
            $totals[0]['sql'],
            'the totals query must not carry the window the group query bounded itself with'
        );
        $this->assertSame('i', $totals[0]['types'], 'user_id only: no synthetic time_from');
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
                'redownloads' => 1, 'reengagements' => 0, 'trusted_count' => 5,
                'refuted_count' => 0, 'unvouched_count' => 1,
                'test_count' => 0,
            ]],
            // Conversion-value distribution for the same group, by the app
            // each postback names. App 997 (registration 7) has its own
            // encoding for 63; app 998 has none, so it decodes through the
            // account-wide set.
            'cv_app_id' => [
                // cv_segment 2: after both breakpoints of encodings effective
                // at 1 (1 and 1 + the horizon), where each meaning is the
                // only one there has been.
                ['grp_day' => $day, 'cv_app_id' => 997, 'conversion_value' => 63, 'coarse_conversion_value' => null, 'cv_segment' => 2, 'cnt' => 2],
                ['grp_day' => $day, 'cv_app_id' => 998, 'conversion_value' => 63, 'coarse_conversion_value' => null, 'cv_segment' => 2, 'cnt' => 1],
                ['grp_day' => $day, 'cv_app_id' => 998, 'conversion_value' => 7, 'coarse_conversion_value' => null, 'cv_segment' => 2, 'cnt' => 1],
                ['grp_day' => $day, 'cv_app_id' => 999, 'conversion_value' => null, 'coarse_conversion_value' => 'high', 'cv_segment' => 2, 'cnt' => 1],
                ['grp_day' => $day, 'cv_app_id' => 998, 'conversion_value' => null, 'coarse_conversion_value' => null, 'cv_segment' => 2, 'cnt' => 1],
            ],
            // The user's encodings: a registration-specific fine encoding
            // overriding the account-wide one for value 63 (worth its goal's
            // fixed value, no override), plus an account-wide coarse
            // encoding. The others are worth their override, whatever their
            // goal says. No history: nothing was ever edited.
            'FROM 202_app_skan_encodings e' => [
                ['registration_id' => 0, 'app_platform' => null, 'app_key' => null, 'app_id' => null, 'fine_value' => 63, 'coarse_value' => null, 'goal_id' => 1, 'revenue_override' => '49.99000', 'effective_at' => 1, 'retired_at' => null],
                ['registration_id' => 7, 'app_platform' => 'ios', 'app_key' => '997', 'app_id' => null, 'fine_value' => 63, 'coarse_value' => null, 'goal_id' => 2, 'revenue_override' => null, 'effective_at' => 1, 'retired_at' => null],
                ['registration_id' => 0, 'app_platform' => null, 'app_key' => null, 'app_id' => null, 'fine_value' => null, 'coarse_value' => 'high', 'goal_id' => 3, 'revenue_override' => '10.00000', 'effective_at' => 1, 'retired_at' => null],
            ],
            'FROM 202_app_skan_encoding_history' => [],
            'SELECT g.goal_id, g.name, v.definition' => [
                ['goal_id' => 1, 'name' => 'purchase', 'definition' => '{"name":"purchase","trigger":{"event":"purchase","where":[]},"threshold":{"count":1},"after":[],"within":null,"repeat":{"mode":"once"},"value":{"type":"none"}}'],
                ['goal_id' => 2, 'name' => 'premium_purchase', 'definition' => '{"name":"premium_purchase","trigger":{"event":"premium","where":[]},"threshold":{"count":1},"after":[],"within":null,"repeat":{"mode":"once"},"value":{"type":"fixed","amount":"99.99"}}'],
                ['goal_id' => 3, 'name' => 'high_value', 'definition' => '{"name":"high_value","trigger":{"event":"whale","where":[]},"threshold":{"count":1},"after":[],"within":null,"repeat":{"mode":"once"},"value":{"type":"fixed","amount":"1.00"}}'],
            ],
        ]);

        $result = (new AppPostbacksController($db, 1))->report(['group_by' => 'day']);
        $this->assertSame('day', $result['data']['group_by']);
        $this->assertSame('UTC', $result['meta']['timezone']);
        // No signature filter given → the report must default to counting
        // verified rows only, and must say so.
        $this->assertSame('trusted-only', $result['meta']['trusted']);
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
        $this->assertSame(0, $group['ambiguous_encoding']);
        $this->assertSame(1, $group['null_conversion_values']);

        // 2 × 99.99 (the registration's own beats account-wide) + 1 × 49.99 + 1 × 10.00
        $this->assertSame(259.97, $group['decoded_revenue']);
        // events must be an object so a group with no decoded events still
        // JSON-encodes as {} rather than [] (consumers key by event name).
        $this->assertInstanceOf(\stdClass::class, $group['events']);
        $events = (array)$group['events'];
        $this->assertSame(['count' => 2, 'revenue' => 199.98], $events['premium_purchase']);
        $this->assertSame(['count' => 1, 'revenue' => 49.99], $events['purchase']);
        $this->assertSame(['count' => 1, 'revenue' => 10.0], $events['high_value']);
    }

    public function testAValueWhoseMeaningChangedInsideTheHorizonIsAmbiguous(): void
    {
        // Fine 10 meant goal 1 until $edit, and goal 2 since. Rows are
        // grouped by INTERVAL(received_at, breakpoints…), so each row here
        // names the segment it fell in: before the edit (only goal 1 ever),
        // inside the horizon after it (both), after it (only goal 2).
        $edit = 1_700_000_000;
        $horizon = \Api\V3\Apps\Apple\SkanEncodingTimeline::HORIZON_SECONDS;
        $segment = static function (int $at) use ($edit, $horizon): int {
            // Breakpoints: 1, 1 + H, $edit, $edit + H.
            return count(array_filter([1, 1 + $horizon, $edit, $edit + $horizon], static fn (int $b): bool => $b <= $at));
        };
        $db = $this->createMysqliMock([
            'AS postbacks' => [['grp_day' => 0, 'postbacks' => 6, 'losses' => 0, 'installs' => 6, 'redownloads' => 0, 'reengagements' => 0,
                'trusted_count' => 6, 'refuted_count' => 0, 'unvouched_count' => 0, 'test_count' => 0]],
            'cv_app_id' => [
                ['grp_day' => 0, 'cv_app_id' => 997, 'conversion_value' => 10, 'coarse_conversion_value' => null, 'cv_segment' => $segment($edit - 5), 'cnt' => 1],
                ['grp_day' => 0, 'cv_app_id' => 997, 'conversion_value' => 10, 'coarse_conversion_value' => null, 'cv_segment' => $segment($edit + 10 * 86400), 'cnt' => 3],
                ['grp_day' => 0, 'cv_app_id' => 997, 'conversion_value' => 10, 'coarse_conversion_value' => null, 'cv_segment' => $segment($edit + $horizon), 'cnt' => 2],
            ],
            'FROM 202_app_skan_encodings e' => [
                ['registration_id' => 7, 'app_platform' => 'ios', 'app_key' => '997', 'app_id' => null, 'fine_value' => 10, 'coarse_value' => null, 'goal_id' => 2, 'revenue_override' => null, 'effective_at' => $edit, 'retired_at' => null],
            ],
            // The history row belongs to registration 5, since deleted: it
            // is found by the app it recorded, not by a registration id.
            'FROM 202_app_skan_encoding_history' => [
                ['registration_id' => 5, 'app_platform' => null, 'app_key' => null, 'app_id' => '997', 'fine_value' => 10, 'coarse_value' => null, 'goal_id' => 1, 'revenue_override' => null, 'effective_at' => 1, 'retired_at' => $edit],
            ],
            'SELECT g.goal_id, g.name, v.definition' => [
                ['goal_id' => 1, 'name' => 'trial', 'definition' => '{"name":"trial","trigger":{"event":"trial","where":[]},"threshold":{"count":1},"after":[],"within":null,"repeat":{"mode":"once"},"value":{"type":"fixed","amount":"1.00"}}'],
                ['goal_id' => 2, 'name' => 'purchase', 'definition' => '{"name":"purchase","trigger":{"event":"purchase","where":[]},"threshold":{"count":1},"after":[],"within":null,"repeat":{"mode":"once"},"value":{"type":"fixed","amount":"5.00"}}'],
            ],
        ]);

        $group = (new AppPostbacksController($db, 1))->report(['group_by' => 'day', 'time_from' => 0])['data']['groups'][0];
        $this->assertSame(6, $group['measurable']);
        $this->assertSame(3, $group['decoded'], 'before the edit and after the horizon');
        $this->assertSame(3, $group['ambiguous_encoding'], 'inside the horizon: credited to neither goal');
        $this->assertSame(0, $group['undecoded']);
        $events = (array)$group['events'];
        $this->assertSame(['count' => 1, 'revenue' => 1.0], $events['trial']);
        $this->assertSame(['count' => 2, 'revenue' => 10.0], $events['purchase']);
        $this->assertSame(11.0, $group['decoded_revenue'], '1 × 1.00 + 2 × 5.00');
    }

    public function testAMeaningWhoseAppCannotBeReadDecodesNothingRatherThanCountAsAccountWide(): void
    {
        // A history row with no app (a NULL app_id) and a current encoding
        // whose registration is not an iOS app are damage. Read as
        // account-wide (app 0), each would decode every app's postbacks
        // carrying its value as its goal; they must match nothing, and be
        // logged.
        $edit = 1_700_000_000;
        $db = $this->createMysqliMock([
            'AS postbacks' => [['grp_day' => 0, 'postbacks' => 2, 'losses' => 0, 'installs' => 2, 'redownloads' => 0, 'reengagements' => 0,
                'trusted_count' => 2, 'refuted_count' => 0, 'unvouched_count' => 0, 'test_count' => 0]],
            'cv_app_id' => [
                // Segment 2 of the breakpoints (1, 1 + H, $edit, $edit + H):
                // from 1 + H, while both damaged meanings applied.
                ['grp_day' => 0, 'cv_app_id' => 997, 'conversion_value' => 10, 'coarse_conversion_value' => null, 'cv_segment' => 2, 'cnt' => 1],
                ['grp_day' => 0, 'cv_app_id' => 997, 'conversion_value' => 11, 'coarse_conversion_value' => null, 'cv_segment' => 2, 'cnt' => 1],
            ],
            'FROM 202_app_skan_encodings e' => [
                ['registration_id' => 9, 'app_platform' => 'android', 'app_key' => 'com.example.app', 'app_id' => null, 'fine_value' => 11, 'coarse_value' => null, 'goal_id' => 2, 'revenue_override' => null, 'effective_at' => 1, 'retired_at' => null],
            ],
            'FROM 202_app_skan_encoding_history' => [
                ['registration_id' => 5, 'app_platform' => null, 'app_key' => null, 'app_id' => null, 'fine_value' => 10, 'coarse_value' => null, 'goal_id' => 2, 'revenue_override' => null, 'effective_at' => 1, 'retired_at' => $edit],
            ],
            'SELECT g.goal_id, g.name, v.definition' => [
                ['goal_id' => 1, 'name' => 'trial', 'definition' => '{"name":"trial","trigger":{"event":"trial","where":[]},"threshold":{"count":1},"after":[],"within":null,"repeat":{"mode":"once"},"value":{"type":"fixed","amount":"1.00"}}'],
                ['goal_id' => 2, 'name' => 'purchase', 'definition' => '{"name":"purchase","trigger":{"event":"purchase","where":[]},"threshold":{"count":1},"after":[],"within":null,"repeat":{"mode":"once"},"value":{"type":"fixed","amount":"5.00"}}'],
            ],
        ]);

        $logged = tempnam(sys_get_temp_dir(), 'p202log');
        $previous = ini_set('error_log', (string)$logged);
        try {
            $group = (new AppPostbacksController($db, 1))->report(['group_by' => 'day', 'time_from' => 0])['data']['groups'][0];
        } finally {
            ini_set('error_log', (string)$previous);
        }
        $log = (string)file_get_contents((string)$logged);
        @unlink((string)$logged);
        $this->assertSame(2, $group['measurable']);
        $this->assertSame(0, $group['decoded']);
        $this->assertSame(2, $group['undecoded']);
        $this->assertSame([], (array)$group['events']);
        $this->assertStringContainsString('registration 5 names no readable iOS app', $log);
        $this->assertStringContainsString('registration 9 names no readable iOS app', $log);
    }

    // ─── Postbacks: verify ───────────────────────────────────────────

    public function testVerifyRequiresABody(): void
    {
        $ctrl = new AppPostbacksController($this->createMysqliMock(), 1);
        $this->expectException(ValidationException::class);
        $ctrl->verify([]);
    }

    public function testVerifyReportsSignatureStateAndTheSignedMessage(): void
    {
        $ctrl = new AppPostbacksController($this->createMysqliMock(), 1);

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
        $ctrl = new AppPostbacksController($this->createMysqliMock(), 1);

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

    // ─── The report's metric list ────────────────────────────────────

    /**
     * The grouped query, the ungrouped totals beside it and the row readers
     * were four copies of the same nine COUNT(DISTINCT ...) columns. They are
     * one list now; this is the floor under that, because the failure mode of
     * a fifth copy drifting is silent — both queries still succeed and return
     * rows, and only the numbers disagree.
     */
    public function testEveryMetricTheReportSelectsIsOneTheReadersLookFor(): void
    {
        $columns = $this->metricColumns();

        $this->assertSame(
            array_keys($columns),
            AppPostbacksController::metricKeys(),
            'metricKeys() must name exactly the aliases metricColumns() selects, in order'
        );

        foreach ($columns as $alias => $expression) {
            // Every metric counts distinct identities, so a postback stored
            // twice cannot inflate one. `postbacks` counts them all; the rest
            // narrow with a CASE.
            $this->assertStringStartsWith('COUNT(DISTINCT', $expression, "metric $alias must de-duplicate");
            if ($alias !== 'postbacks') {
                $this->assertStringContainsString('CASE WHEN', $expression, "metric $alias should be conditional");
            }
        }
    }

    /**
     * The report breaks conversion_type out into its own metrics. The stored
     * vocabulary lives in AdAttributionKitProtocol; a type added there and not
     * here still lands in `postbacks` but silently gets no column of its own,
     * so the page would under-report a whole class of conversion without any
     * query failing.
     */
    public function testEveryStoredConversionTypeGetsItsOwnMetric(): void
    {
        $this->assertSame(
            AdAttributionKitProtocol::CONVERSION_TYPES,
            array_keys($this->conversionMetrics()),
            'CONVERSION_METRICS must cover exactly the stored conversion_type vocabulary'
        );

        $columns = $this->metricColumns();
        foreach ($this->conversionMetrics() as $conversionType => $alias) {
            $this->assertArrayHasKey($alias, $columns, "no column for conversion type $conversionType");
            $this->assertStringContainsString(
                "conversion_type = '$conversionType'",
                $columns[$alias],
                "column $alias must count $conversionType rows"
            );
        }
    }

    /**
     * The report's grouped aggregate queries. The ungrouped totals select the
     * same metric columns, so 'AS postbacks' alone no longer tells the two
     * apart; GROUP BY does.
     *
     * @return list<array{sql: string, types: string}>
     */
    private function groupedAggregates(): array
    {
        return array_values(array_filter(
            $this->capturedStatements('SELECT'),
            static fn(array $s): bool => str_contains($s['sql'], 'AS postbacks')
                && str_contains($s['sql'], 'GROUP BY')
        ));
    }

    /**
     * Its ungrouped counterpart: the same metrics over the whole window.
     *
     * @return list<array{sql: string, types: string}>
     */
    private function ungroupedTotals(): array
    {
        return array_values(array_filter(
            $this->capturedStatements('SELECT'),
            static fn(array $s): bool => str_contains($s['sql'], 'AS postbacks')
                && !str_contains($s['sql'], 'GROUP BY')
        ));
    }

    /**
     * app_ids narrows to a SET of apps, which app_id alone cannot do.
     *
     * The Setup page's development nudges need one grouped read restricted
     * to the apps it is asking about. Without this filter the only lever was
     * the group LIMIT, and groups come back busiest first, so apps the caller
     * did not ask about took the slots and the answer it wanted was cut.
     */
    public function testRegistrationIdsFiltersToTheNamedAppsInEveryQuery(): void
    {
        $db = $this->capturingDb();
        (new AppPostbacksController($db, 1))->report([
            'group_by' => 'registration',
            'registration_ids' => [990077001, 525463029],
        ]);

        $issued = [
            'grouped' => $this->groupedAggregates(),
            'totals' => $this->ungroupedTotals(),
        ];
        foreach ($issued as $which => $queries) {
            $this->assertNotSame([], $queries, "$which query was not issued");
            $this->assertStringContainsString('registration_id IN (?, ?)', $queries[0]['sql'], $which);
        }
        // user_id, two registration ids, then the grouped query's own LIMIT bind.
        $this->assertSame('iiii', $this->groupedAggregates()[0]['types']);
        $this->assertSame('iii', $this->ungroupedTotals()[0]['types']);
    }

    public function testRegistrationIdsAcceptsACommaStringAndTrimsIt(): void
    {
        $db = $this->capturingDb();
        (new AppPostbacksController($db, 1))->report([
            'group_by' => 'registration',
            'registration_ids' => ' 990077001 , 525463029 ',
        ]);

        $this->assertStringContainsString('registration_id IN (?, ?)', $this->groupedAggregates()[0]['sql']);
        $this->assertSame('iiii', $this->groupedAggregates()[0]['types']);
    }

    public function testRegistrationIdsCollapsesDuplicatesRatherThanRepeatingAPlaceholder(): void
    {
        $db = $this->capturingDb();
        (new AppPostbacksController($db, 1))->report([
            'group_by' => 'registration',
            'registration_ids' => [990077001, 990077001, 525463029],
        ]);

        $this->assertStringContainsString('registration_id IN (?, ?)', $this->groupedAggregates()[0]['sql']);
    }

    public function testAnAbsentRegistrationIdsFilterAddsNothing(): void
    {
        foreach ([[], '', null] as $absent) {
            $db = $this->capturingDb();
            (new AppPostbacksController($db, 1))->report(['group_by' => 'registration', 'registration_ids' => $absent]);
            $this->assertStringNotContainsString(
                'registration_id IN',
                $this->groupedAggregates()[0]['sql'],
                'an empty registration_ids must not narrow anything: ' . var_export($absent, true)
            );
        }
    }

    /**
     * @dataProvider refusedRegistrationIds
     * @param mixed $ids
     */
    public function testRegistrationIdsRefusesWhatItCannotBindFaithfully(mixed $ids, string $because): void
    {
        $db = $this->capturingDb();
        try {
            (new AppPostbacksController($db, 1))->report(['group_by' => 'registration', 'registration_ids' => $ids]);
            $this->fail("registration_ids accepted $because");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('registration_ids', $e->getFieldErrors(), $because);
        }
        $this->assertSame([], $this->groupedAggregates(), "the query ran anyway for $because");
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function refusedRegistrationIds(): array
    {
        return [
            // The saturating case registration_id itself refuses: the int
            // cast MOVES this to PHP_INT_MAX, so an accepted filter would
            // silently be about a different app than the caller named.
            'a value the int cast moves' => [['99999999999999999999'], 'a saturating id'],
            'a float string' => [['1.5'], 'a non-integer id'],
            'a word' => [['nine'], 'a word'],
            // '1,,2' is three elements, the middle one empty. Dropping it
            // silently would widen the filter the caller asked for.
            'an empty element in a comma string' => ['990077001,,525463029', 'a blank element'],
            'a trailing comma' => ['990077001,', 'a trailing comma'],
            'a nested array' => [[[990077001]], 'a nested array'],
            'an object where a list belongs' => [new \stdClass(), 'an object'],
            'more ids than any page lists' => [range(1, 501), '501 ids'],
        ];
    }

    /**
     * registration_id and registration_ids are ANDed, which is what the guide promises.
     *
     * Stated in documentation/api/19-app-measurement.md, so it is
     * executed here rather than asserted in prose: both clauses go into the
     * same WHERE, so the two must agree for a row to match.
     */
    public function testRegistrationIdAndRegistrationIdsBothApply(): void
    {
        $db = $this->capturingDb();
        (new AppPostbacksController($db, 1))->report([
            'group_by' => 'registration',
            'registration_id' => 990077001,
            'registration_ids' => [990077001, 525463029],
        ]);

        $sql = $this->groupedAggregates()[0]['sql'];
        $this->assertStringContainsString('registration_id = ?', $sql);
        $this->assertStringContainsString('registration_id IN (?, ?)', $sql);
        // user_id, the single registration_id, two list ids, the LIMIT.
        $this->assertSame('iiiii', $this->groupedAggregates()[0]['types']);
    }

    public function testRegistrationIdsAcceptsExactlyTheCeiling(): void
    {
        $db = $this->capturingDb();
        (new AppPostbacksController($db, 1))->report([
            'group_by' => 'registration',
            'registration_ids' => range(1, 500),
        ]);

        $this->assertStringContainsString(
            'registration_id IN (' . implode(', ', array_fill(0, 500, '?')) . ')',
            $this->groupedAggregates()[0]['sql']
        );
        // user_id + 500 ids + the LIMIT bind, and one type letter each.
        $this->assertSame(502, strlen($this->groupedAggregates()[0]['types']));
    }

    /** @return array<string, string> */
    private function metricColumns(): array
    {
        $method = new \ReflectionMethod(AppPostbacksController::class, 'metricColumns');
        $method->setAccessible(true);

        return $method->invoke(null, 'trusted = 1', 'ident', 'won', 'first', 'development');
    }

    /** @return array<string, string> */
    private function conversionMetrics(): array
    {
        $constant = new \ReflectionClassConstant(AppPostbacksController::class, 'CONVERSION_METRICS');

        return $constant->getValue();
    }
}
