<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use Api\V3\Apps\Android\Integrity\IntegrityMode;
use Api\V3\Apps\Android\LockedInstall;
use PHPUnit\Framework\TestCase;

/**
 * The registration a reclassification builds carries the registration's
 * live policy, not the install's snapshot of it.
 *
 * 202_app_installs and 202_app_registrations both have `integrity_mode`:
 * the install's is the mode it arrived under, the registration's is the
 * mode now. PendingClickSettler and IntegrityVerifier read
 * `SELECT i.*, r.…` and built AppPolicy::fromRow() from that row, so the
 * policy's integrityMode was the install's — the settler even selected the
 * registration's under an alias nothing read. This reads the one locked
 * row both paths now share, on a real database, with the two modes apart
 * in both directions.
 *
 * @group integration
 */
final class LockedInstallPolicyTest extends TestCase
{
    use AndroidDatabase;

    private static function storeInstall(int $rowId, string $installMode): void
    {
        self::fixture("INSERT INTO 202_app_installs SET install_row_id = $rowId, user_id = 1, registration_id = 5,
            install_uuid = '" . sprintf('%08d-0000-4000-8000-000000000000', $rowId) . "', body_hash = '" . str_repeat('0', 64) . "',
            store = 'google_play', match_state = 'pending_click', match_reason = 'waiting', referrer_status = 'ok',
            integrity_mode = '$installMode', received_at = 1");
    }

    /** @return array{IntegrityMode, string} the policy's mode and the row's own integrity_mode */
    private function read(int $rowId): array
    {
        $row = self::$db->begin_transaction() ? LockedInstall::read($this->conn, $rowId) : null;
        self::$db->commit();
        self::assertIsArray($row);

        return [LockedInstall::registration($row)->policy->integrityMode, (string) $row['integrity_mode']];
    }

    public function testTheRegistrationsLiveModeNotTheInstallsSnapshot(): void
    {
        self::fixture("UPDATE 202_app_registrations SET integrity_mode = 'require' WHERE registration_id = 5");
        self::storeInstall(71, 'off');
        [$policy, $own] = $this->read(71);
        self::assertSame(IntegrityMode::REQUIRE, $policy, 'the registration requires integrity now');
        self::assertSame('off', $own, 'and the row still carries the install\'s own snapshot for the paths that read it');

        self::fixture("UPDATE 202_app_registrations SET integrity_mode = 'off' WHERE registration_id = 5");
        self::storeInstall(72, 'require');
        [$policy, $own] = $this->read(72);
        self::assertSame(IntegrityMode::OFF, $policy);
        self::assertSame('require', $own);
    }

    public function testTheWholePolicyIsTheRegistrations(): void
    {
        self::fixture("UPDATE 202_app_registrations SET accept_test_signals = 1, attribution_window_days = 12, trust_client_revenue = 1,
            integrity_mode = 'observe' WHERE registration_id = 5");
        self::storeInstall(73, 'off');
        $row = self::$db->begin_transaction() ? LockedInstall::read($this->conn, 73) : null;
        self::$db->commit();
        $policy = LockedInstall::registration((array) $row)->policy;
        self::assertSame([true, 12, true, IntegrityMode::OBSERVE], [$policy->acceptTestSignals, $policy->attributionWindowDays, $policy->trustClientRevenue, $policy->integrityMode]);
    }

    /**
     * Both reclassification paths read through LockedInstall, and no other
     * query reads an install's own columns (`i.*`) beside its registration:
     * a second one is where the shadowed column comes back.
     */
    public function testBothReclassificationPathsReadThroughTheOneLockedRow(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['api/v3/Apps/Android/PendingClickSettler.php', 'api/v3/Apps/Android/Integrity/IntegrityVerifier.php'] as $path) {
            $src = (string) file_get_contents($root . '/' . $path);
            self::assertStringContainsString('LockedInstall::read($this->conn, $installRowId)', $src, $path);
            self::assertStringContainsString('LockedInstall::registration($row)', $src, $path);
            self::assertStringNotContainsString('AppPolicy::fromRow', $src, $path . ' builds no policy of its own');
        }
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = substr((string) $file->getPathname(), strlen($root) + 1);
            if (!str_ends_with($path, '.php') || preg_match('#^(vendor|tests|\.git|\.claude|sdk|go-cli|node_modules)/#', $path) === 1) {
                continue;
            }
            $src = (string) file_get_contents((string) $file->getPathname());
            if (preg_match('/SELECT\s+i\.\*[^;]*202_app_installs\s+i\s+JOIN\s+202_app_registrations/s', $src) === 1
                && $path !== 'api/v3/Apps/Android/LockedInstall.php') {
                $offenders[] = $path;
            }
        }
        self::assertSame([], $offenders, 'read an install beside its registration through LockedInstall');
    }
}
