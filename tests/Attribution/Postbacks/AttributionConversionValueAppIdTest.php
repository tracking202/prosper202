<?php

declare(strict_types=1);

namespace Tests\Attribution\Postbacks;

use Api\V3\Controllers\AttributionConversionValuesController;
use Api\V3\Exception\ValidationException;
use Tests\TestCase;

/**
 * What a conversion-value rule's app_id is allowed to be, checked over the
 * capturing double so each case pins the statement that was (or was not) sent.
 *
 * The field is declared 'i', and Controller::validatePayload() casts before any
 * hook of this controller sees the payload: 1.5 arrived as 1, '1e2' as 100 and
 * a 20-digit string as PHP_INT_MAX, so the rule was silently scoped to a
 * DIFFERENT app — and GET /attribution/schema serves rules by
 * `app_id = ? OR app_id = 0`, so that app's builds then decoded conversion
 * values through a rule their operator never wrote. Same defect the app
 * registry had; the difference here is that 0 is the legitimate account-wide
 * default scope rather than a reserved value, so 0 must still be accepted.
 */
final class AttributionConversionValueAppIdTest extends TestCase
{
    use CapturingMysqli;

    /** The rule create()/update() operate on in these tests: a fine rule. */
    private const CURRENT_ROW = [
        'rule_id' => 5, 'app_id' => 0, 'fine_value' => 10, 'coarse_value' => null,
        'event_name' => 'purchase', 'revenue' => '1.00000', 'user_id' => 1,
    ];

    /**
     * @dataProvider malformedAppIds
     */
    public function testAMalformedAppIdIsRejectedBeforeTheInsert(mixed $appId): void
    {
        $ctrl = new AttributionConversionValuesController($this->capturingDb(), 1);
        try {
            $ctrl->create(['app_id' => $appId, 'fine_value' => 10, 'event_name' => 'purchase']);
            $this->fail('Expected a ValidationException for app_id ' . var_export($appId, true));
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('app_id', $e->getFieldErrors());
        }
        $this->assertSame(
            [],
            $this->capturedStatements('INSERT'),
            'A rejected app_id must not reach the INSERT'
        );
    }

    /**
     * @dataProvider malformedAppIds
     */
    public function testAMalformedAppIdIsRejectedBeforeTheUpdate(mixed $appId): void
    {
        $db = $this->capturingDb([
            'FROM 202_attribution_conversion_values WHERE rule_id = ?' => [self::CURRENT_ROW],
        ]);
        $ctrl = new AttributionConversionValuesController($db, 1);
        try {
            $ctrl->update(5, ['app_id' => $appId, 'event_name' => 'renamed']);
            $this->fail('Expected a ValidationException for app_id ' . var_export($appId, true));
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('app_id', $e->getFieldErrors());
        }
        $this->assertSame(
            [],
            $this->capturedStatements('UPDATE'),
            'A rejected app_id must not reach the UPDATE'
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
    public static function malformedAppIds(): array
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
     * @dataProvider usableAppScopes
     */
    public function testAUsableAppIdReachesTheInsert(mixed $appId, int $stored): void
    {
        $ctrl = new AttributionConversionValuesController($this->capturingDb(), 1);
        try {
            $ctrl->create(['app_id' => $appId, 'fine_value' => 10, 'event_name' => 'purchase']);
        } catch (ValidationException $e) {
            $this->fail('A usable app_id must not be refused: ' . $e->getMessage());
        } catch (\Throwable) {
            // Reaching the INSERT is the point; the mock cannot complete the
            // round-trip (insert_id is C-backed), exactly as in the
            // neighbouring controller tests.
        }
        $inserts = $this->capturedStatements('INSERT');
        $this->assertCount(1, $inserts);
        $this->assertSame($stored, $this->boundValue($inserts[0], 'app_id'));
    }

    /**
     * @dataProvider usableAppScopes
     */
    public function testAUsableAppIdReachesTheUpdate(mixed $appId, int $stored): void
    {
        $db = $this->capturingDb([
            'FROM 202_attribution_conversion_values WHERE rule_id = ?' => [self::CURRENT_ROW],
        ]);
        $ctrl = new AttributionConversionValuesController($db, 1);
        try {
            $ctrl->update(5, ['app_id' => $appId]);
        } catch (ValidationException $e) {
            $this->fail('A usable app_id must not be refused: ' . $e->getMessage());
        }
        $updates = $this->capturedStatements('UPDATE');
        $this->assertCount(1, $updates);
        $this->assertSame($stored, $this->boundValue($updates[0], 'app_id'));
    }

    /**
     * 0 is the account-wide default scope — the one place this controller
     * parts company with AttributionAppsController, which reserves 0 and
     * rejects it. Both spellings of it, since a form-encoded body and the Go
     * CLI send numbers as strings.
     *
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function usableAppScopes(): array
    {
        return [
            'account-wide default'        => [0, 0],
            'account-wide default string' => ['0', 0],
            'app store id'                => [525463029, 525463029],
            'app store id as digits'      => ['525463029', 525463029],
        ];
    }

    public function testTheKindSwitchSentinelStillWorks(): void
    {
        // The app_id guard rejects an explicit null; fine_value/coarse_value
        // must keep theirs, where null is the documented one-request switch
        // ({"fine_value": null, "coarse_value": "high"}).
        $db = $this->capturingDb([
            'FROM 202_attribution_conversion_values WHERE rule_id = ?' => [self::CURRENT_ROW],
        ]);
        $ctrl = new AttributionConversionValuesController($db, 1);
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
