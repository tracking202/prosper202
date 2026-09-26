<?php

declare(strict_types=1);

namespace Tests\Apps\Apple;

use Api\V3\Controllers\AppSkanEncodingsController;
use Api\V3\Exception\ValidationException;
use Tests\TestCase;

/**
 * What a SKAN encoding's registration_id is allowed to be, checked over the
 * capturing double so each case pins the statement that was (or was not) sent.
 *
 * The field is declared 'i', and Controller::validatePayload() casts before any
 * hook of this controller sees the payload: 1.5 arrives as 1, '1e2' as 100 and
 * a 20-digit string as PHP_INT_MAX, so the encoding would be silently scoped
 * to a DIFFERENT registration — and GET /apps/schema serves encodings by
 * `registration_id = ? OR registration_id = 0`, so that app's builds would
 * then encode through a value their operator never wrote (CLAUDE.md #18).
 * 0 is the legitimate account-wide scope and must still be accepted; any
 * other value must name one of the caller's own iOS registrations (plan
 * §4.5: an encoding for an app nobody registered decodes nothing).
 */
final class SkanEncodingRegistrationIdTest extends TestCase
{
    use CapturingMysqli;

    /** The encoding create()/update() operate on in these tests: a fine one. */
    private const CURRENT_ROW = [
        'encoding_id' => 5, 'registration_id' => 0, 'fine_value' => 10, 'coarse_value' => null,
        'event_name' => 'purchase', 'revenue' => '1.00000', 'user_id' => 1,
    ];

    /** The caller's own iOS registration, as the ownership lookup returns it. */
    private const MY_IOS_REGISTRATION = ['FROM 202_app_registrations WHERE registration_id = ? AND user_id = ?' => [['platform' => 'ios']]];

    /**
     * @dataProvider malformedRegistrationIds
     */
    public function testAMalformedRegistrationIdIsRejectedBeforeTheInsert(mixed $appId): void
    {
        $ctrl = new AppSkanEncodingsController($this->capturingDb(), 1);
        try {
            $ctrl->create(['registration_id' => $appId, 'fine_value' => 10, 'event_name' => 'purchase']);
            $this->fail('Expected a ValidationException for registration_id ' . var_export($appId, true));
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('registration_id', $e->getFieldErrors());
        }
        $this->assertSame(
            [],
            $this->capturedStatements('INSERT'),
            'A rejected registration_id must not reach the INSERT'
        );
    }

    /**
     * @dataProvider malformedRegistrationIds
     */
    public function testAMalformedRegistrationIdIsRejectedBeforeTheUpdate(mixed $appId): void
    {
        $db = $this->capturingDb([
            'FROM 202_app_skan_encodings WHERE encoding_id = ?' => [self::CURRENT_ROW],
        ]);
        $ctrl = new AppSkanEncodingsController($db, 1);
        try {
            $ctrl->update(5, ['registration_id' => $appId, 'event_name' => 'renamed']);
            $this->fail('Expected a ValidationException for registration_id ' . var_export($appId, true));
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('registration_id', $e->getFieldErrors());
        }
        $this->assertSame(
            [],
            $this->capturedStatements('UPDATE'),
            'A rejected registration_id must not reach the UPDATE'
        );
    }

    /**
     * Every spelling the 'i' cast would rewrite into some other app's scope.
     * app_id is NOT NULL with a declared default, so an explicit null is bad
     * input too — it is not the "clear" sentinel fine_value/coarse_value use
     * (see testTheKindSwitchSentinelStillWorks), and letting it fall through
     * to the cast resolved it to 0, the widest scope there is.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function malformedRegistrationIds(): array
    {
        return [
            'float truncated by the cast'   => [1.5],
            'exponent string'              => ['1e2'],
            'decimal string'               => ['525463029.9'],
            'leading whitespace'           => [' 1'],
            'digits past PHP_INT_MAX'      => ['99999999999999999999'],
            'negative'                     => [-5],
            'explicit null'                => [null],
        ];
    }

    /**
     * @dataProvider usableRegistrationScopes
     */
    public function testAUsableRegistrationIdReachesTheInsert(mixed $appId, int $stored): void
    {
        $ctrl = new AppSkanEncodingsController($this->capturingDb(self::MY_IOS_REGISTRATION), 1);
        try {
            $ctrl->create(['registration_id' => $appId, 'fine_value' => 10, 'event_name' => 'purchase']);
        } catch (ValidationException $e) {
            $this->fail('A usable registration_id must not be refused: ' . $e->getMessage());
        } catch (\Throwable) {
            // Reaching the INSERT is the point; the mock cannot complete the
            // round-trip (insert_id is C-backed), exactly as in the
            // neighbouring controller tests.
        }
        $inserts = $this->capturedStatements('INSERT');
        $this->assertCount(1, $inserts);
        $this->assertSame($stored, $this->boundValue($inserts[0], 'registration_id'));
    }

    /**
     * @dataProvider usableRegistrationScopes
     */
    public function testAUsableRegistrationIdReachesTheUpdate(mixed $appId, int $stored): void
    {
        $db = $this->capturingDb([
            'FROM 202_app_skan_encodings WHERE encoding_id = ?' => [self::CURRENT_ROW],
        ] + self::MY_IOS_REGISTRATION);
        $ctrl = new AppSkanEncodingsController($db, 1);
        try {
            $ctrl->update(5, ['registration_id' => $appId]);
        } catch (ValidationException $e) {
            $this->fail('A usable registration_id must not be refused: ' . $e->getMessage());
        }
        $updates = $this->capturedStatements('UPDATE');
        $this->assertCount(1, $updates);
        $this->assertSame($stored, $this->boundValue($updates[0], 'registration_id'));
    }

    /**
     * 0 is the account-wide scope, which no registration can be. Both
     * spellings of each, since a form-encoded body and the Go CLI send
     * numbers as strings.
     *
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function usableRegistrationScopes(): array
    {
        return [
            'account-wide'              => [0, 0],
            'account-wide as a string'  => ['0', 0],
            'a registration'            => [7, 7],
            'a registration as digits'  => ['7', 7],
        ];
    }

    /**
     * A non-zero scope must be a registration the caller owns and an iOS
     * one; each refusal is a 422 on the field, before anything is written.
     *
     * @dataProvider refusedRegistrations
     * @param array<string, list<array<string, mixed>>> $lookup
     */
    public function testARegistrationThatIsNotMineOrNotIosIsRefusedBeforeTheInsert(array $lookup, string $because): void
    {
        $ctrl = new AppSkanEncodingsController($this->capturingDb($lookup), 1);
        try {
            $ctrl->create(['registration_id' => 7, 'fine_value' => 10, 'event_name' => 'purchase']);
            $this->fail('registration 7 was accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($because, $e->getFieldErrors()['registration_id'] ?? '');
        }
        $this->assertSame([], $this->capturedStatements('INSERT'));
    }

    /** @return array<string, array{0: array<string, list<array<string, mixed>>>, 1: string}> */
    public static function refusedRegistrations(): array
    {
        return [
            'not in this account' => [[], 'No registration 7 in this account'],
            'an Android app' => [
                ['FROM 202_app_registrations WHERE registration_id = ? AND user_id = ?' => [['platform' => 'android']]],
                'iOS apps only',
            ],
        ];
    }

    public function testTheKindSwitchSentinelStillWorks(): void
    {
        // The app_id guard rejects an explicit null; fine_value/coarse_value
        // must keep theirs, where null is the documented one-request switch
        // ({"fine_value": null, "coarse_value": "high"}).
        $db = $this->capturingDb([
            'FROM 202_app_skan_encodings WHERE encoding_id = ?' => [self::CURRENT_ROW],
        ]);
        $ctrl = new AppSkanEncodingsController($db, 1);
        try {
            $ctrl->update(5, ['fine_value' => null, 'coarse_value' => 'high']);
        } catch (ValidationException $e) {
            $this->fail('A kind switch must still be accepted: ' . $e->getMessage());
        }
        $updates = $this->capturedStatements('UPDATE');
        $this->assertCount(1, $updates);
        $this->assertNull($this->boundValue($updates[0], 'fine_value'));
        $this->assertSame('high', $this->boundValue($updates[0], 'coarse_value'));
    }

    /**
     * The value bound for one column of a captured INSERT or UPDATE, found by
     * the column's position in the statement rather than a hardcoded index.
     *
     * @param array{sql: string, types: string, values: mixed[]} $statement
     */
    private function boundValue(array $statement, string $column): mixed
    {
        $sql = $statement['sql'];
        if (preg_match('/^INSERT INTO \S+ \(([^)]*)\)/', $sql, $m) === 1) {
            $columns = array_map(trim(...), explode(',', $m[1]));
        } elseif (preg_match('/^UPDATE \S+ SET (.*) WHERE /', $sql, $m) === 1) {
            $columns = array_map(
                static fn(string $set): string => trim(explode('=', $set, 2)[0]),
                explode(',', $m[1])
            );
        } else {
            $this->fail("Cannot read bound columns out of: $sql");
        }

        $index = array_search($column, $columns, true);
        $this->assertNotFalse($index, "$column is not written by: $sql");
        $this->assertArrayHasKey($index, $statement['values'], "No value bound for $column");
        return $statement['values'][$index];
    }
}
