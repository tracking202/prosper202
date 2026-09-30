<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\CapabilitiesController;
use Api\V3\Controllers\ReportsController;
use Api\V3\Exception\ValidationException;
use Tests\TestCase;

/**
 * The report breakdown dimensions have one list, ReportsController's
 * BREAKDOWNS. /capabilities advertises it as features.report_breakdowns so
 * the Go CLI can validate against the server; the CLI's offline copy
 * (breakdownDimensions in go-cli/cmd/aliases.go) is read here and compared,
 * because it once lacked `region` and refused a dimension the server has.
 */
final class ReportBreakdownsCapabilityTest extends TestCase
{
    /** @return list<string> */
    private static function breakdownKeys(): array
    {
        $const = new \ReflectionClassConstant(ReportsController::class, 'BREAKDOWNS');
        return array_keys((array) $const->getValue());
    }

    public function testCapabilitiesAdvertiseTheControllersOwnDimensions(): void
    {
        $db = $this->createMysqliMock([
            'SELECT version FROM 202_version' => ['version' => '1.2.3'],
            'CONVERT_TZ' => ['tz' => '2000-01-01 00:00:00'],
        ]);

        $result = (new CapabilitiesController($db))->capabilities();

        $this->assertSame(self::breakdownKeys(), $result['data']['features']['report_breakdowns']);
        $this->assertSame(self::breakdownKeys(), ReportsController::breakdownDimensions());
    }

    public function testBreakdownRefusesADimensionTheListDoesNotHave(): void
    {
        $controller = new ReportsController($this->createMysqliMock(), 1);
        try {
            $controller->breakdown(['breakdown' => 'referer']);
            $this->fail('an unknown breakdown was accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(implode(', ', ReportsController::breakdownDimensions()), json_encode($e->getFieldErrors(), JSON_THROW_ON_ERROR));
        }
    }

    public function testTheGoCliOfflineListIsTheControllersList(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/go-cli/cmd/aliases.go');
        $this->assertSame(1, preg_match('/^var breakdownDimensions = \[\]string\{([^}]*)\}/m', $src, $m), 'go-cli/cmd/aliases.go declares breakdownDimensions');
        preg_match_all('/"([^"]+)"/', $m[1], $values);
        $this->assertSame(self::breakdownKeys(), $values[1]);
    }
}
