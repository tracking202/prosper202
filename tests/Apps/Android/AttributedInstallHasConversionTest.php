<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use PHPUnit\Framework\TestCase;
use Tests\Support\SqlLiteralText;

/**
 * "An attributed install row always has its conversion" (plan §5.2) is a
 * fact of the write path, not of a retry's good luck. These pin that path
 * over the whole tree:
 *
 * 1. Who writes 202_app_installs: the intake inserts and classifies
 *    (InstallIntake), the events intake sets has_events and nothing else,
 *    the user purge deletes, and retention deletes through its classes.
 *    A new writer — above all one that could set match_state — fails here.
 * 2. The one statement that sets match_state is InstallIntake::settle(),
 *    which evaluates the install and refuses to finish an attributed,
 *    credited install whose install goal wrote no conversion; and settle()
 *    is called only from inside a transaction: the intake's record() (run
 *    by `$this->conn->transaction($work)`) and the settler's $work.
 *    The Play Integrity worker settles through the same method, in its own
 *    transaction, and its other writes touch only the integrity_* columns.
 *    settle() applies the `require` gate before it writes anything, so no
 *    caller can attribute an install whose verdict has not passed. The one
 *    other match_state write is UnverifiableInstalls' retirement of an
 *    install whose registration is gone: pending_integrity to the constant
 *    integrity_unverified, never touching trust, click or conversion.
 *    The third is OrphanedPendingClicks' retirement of a pending click
 *    whose registration is gone: pending_click to the constant bad_token
 *    (refuted), never touching click or conversion.
 *    The trust bit alone is re-judged when the registration's test-signal
 *    policy changes (InstallIntake::rejudgeTestInstalls()), and that moves
 *    the install's outcomes and conversion in the same transaction.
 * 3. Every redirect fallback that substitutes a placeholder for [[subid]]
 *    while MySQL is down also empties [[p202_install_token]] (plan §5.1):
 *    there is no click to sign, and a literal placeholder would reach Play.
 */
final class AttributedInstallHasConversionTest extends TestCase
{
    private const WRITERS = [
        'api/v3/Apps/Android/InstallIntake.php' => ['insert', 'update'],
        'api/v3/Apps/Android/InstallEventsIntake.php' => ['update'],
        'api/v3/Apps/Android/Integrity/IntegrityVerifier.php' => ['update'],
        'api/v3/Apps/Android/Integrity/UnverifiableInstalls.php' => ['update'],
        'api/v3/Apps/Android/OrphanedPendingClicks.php' => ['update'],
        'api/v3/Apps/AppDataPurge.php' => ['delete'],
    ];

    /** @return array<string, string> relative path => SQL text */
    private static function tree(): array
    {
        static $tree = null;
        if ($tree !== null) {
            return $tree;
        }
        $root = dirname(__DIR__, 3);
        $tree = [];
        foreach (SqlLiteralText::phpFiles($root) as $path) {
            $tree[substr($path, strlen($root) + 1)] = SqlLiteralText::of((string) file_get_contents($path));
        }

        return $tree;
    }

    /** @return list<string> */
    public static function installWrites(string $sql): array
    {
        $verbs = [];
        foreach ([
            'insert' => '/\b(?:INSERT|REPLACE)\b[^;]{0,40}?\bINTO\s+`?202_app_installs`?\b/i',
            'update' => '/\bUPDATE\s+`?202_app_installs`?\b/i',
            'delete' => '/\bDELETE\b[^;]{0,40}?\bFROM\s+`?202_app_installs`?\b/i',
        ] as $verb => $pattern) {
            if (preg_match($pattern, $sql) === 1) {
                $verbs[] = $verb;
            }
        }

        return $verbs;
    }

    public function testOnlyTheIntakeWritesInstalls(): void
    {
        $tree = self::tree();
        self::assertGreaterThan(300, count($tree), 'the walk found too few files to be the whole tree');
        $found = [];
        foreach ($tree as $relative => $sql) {
            $verbs = self::installWrites($sql);
            if ($verbs !== []) {
                $found[$relative] = $verbs;
            }
        }
        ksort($found);
        $expected = self::WRITERS;
        ksort($expected);
        self::assertSame($expected, $found, 'a new writer of 202_app_installs bypasses the transaction that keeps an attributed install with its conversion');

        // The events intake flips its retention hint and touches nothing else.
        preg_match_all('/UPDATE\s+202_app_installs\s+SET\s+([^;]*?)\s+WHERE/i', $tree['api/v3/Apps/Android/InstallEventsIntake.php'], $m);
        self::assertSame(['has_events = 1'], $m[1]);

        // The integrity worker writes its own columns directly; the match
        // state, trust, click and conversion move only through settle().
        preg_match_all('/UPDATE\s+202_app_installs\s+SET\s+([^;]*?)\s+WHERE/i', $tree['api/v3/Apps/Android/Integrity/IntegrityVerifier.php'], $m);
        self::assertCount(3, $m[1], 'the claim, the retry note and the verdict');
        foreach ($m[1] as $set) {
            preg_match_all('/(?:^|,)\s*([a-z_]+)\s*=/', $set, $columns);
            foreach ($columns[1] as $column) {
                self::assertStringStartsWith('integrity_', $column, 'the integrity worker sets ' . $column . ' directly: ' . $set);
            }
        }

        // Installs whose registration is gone are retired by two constant
        // transitions, each from the pending state it ends: the held install
        // becomes integrity_unverified (never attributed, so nothing to
        // convert), the queued verdict `error`. Trust, click and conversion
        // are never written, so this writer cannot make anything payable.
        self::assertSame(2, preg_match_all('/\bUPDATE\b/i', $tree['api/v3/Apps/Android/Integrity/UnverifiableInstalls.php']), 'the retirement writes two statements');
        preg_match_all('/UPDATE\s+202_app_installs\s+SET\s+([^;]*?)\s+WHERE\s+([^;]*?)\s+AND\s+\?/i', $tree['api/v3/Apps/Android/Integrity/UnverifiableInstalls.php'], $m, PREG_SET_ORDER);
        self::assertSame([
            ["match_state = 'integrity_unverified', match_reason = ?, settled_at = ?", "match_state = 'pending_integrity'"],
            ["integrity_state = 'error', integrity_reason = ?, integrity_next_at = NULL", "integrity_state = 'pending'"],
        ], array_map(static fn (array $s): array => [preg_replace('/\s+/', ' ', $s[1]), preg_replace('/\s+/', ' ', $s[2])], $m));

        // Pending clicks whose registration is gone are retired by one
        // constant transition, from the pending state it ends, to a refuted
        // state; click and conversion are never written, so this writer
        // cannot make anything payable.
        $orphans = $tree['api/v3/Apps/Android/OrphanedPendingClicks.php'];
        self::assertSame(1, preg_match_all('/\bUPDATE\b/i', $orphans), 'the retirement writes one statement');
        preg_match_all('/UPDATE\s+202_app_installs\s+SET\s+([^;]*?)\s+WHERE\s+([^;]*?)\s+AND\s+\?/i', $orphans, $m, PREG_SET_ORDER);
        self::assertSame([
            ["match_state = 'bad_token', match_reason = ?, trusted = 0, settled_at = ?", "match_state = 'pending_click'"],
        ], array_map(static fn (array $s): array => [preg_replace('/\s+/', ' ', $s[1]), preg_replace('/\s+/', ' ', $s[2])], $m));

        // Retention deletes through its classes, whose table is data: the
        // only runtime-table DELETE, in a file that never names the table.
        self::assertStringContainsString("'DELETE FROM ' . \$class->table", (string) file_get_contents(dirname(__DIR__, 3) . '/api/v3/Apps/AppRetention.php'));
    }

    public function testOneStatementSetsTheMatchStateAndItRunsInATransaction(): void
    {
        $intake = (string) file_get_contents(dirname(__DIR__, 3) . '/api/v3/Apps/Android/InstallIntake.php');
        $sql = self::tree()['api/v3/Apps/Android/InstallIntake.php'];
        self::assertSame(1, preg_match_all('/UPDATE\s+202_app_installs\s+SET\s+match_state\b/i', $sql), 'one UPDATE sets match_state');
        self::assertSame(1, preg_match_all('/\bfunction settle\(/', $intake));

        // settle(): the state, then the evaluation, then the refusal to leave
        // an attributed, credited install without its conversion.
        $settle = substr($intake, (int) strpos($intake, 'function settle('));
        $settle = substr($settle, 0, (int) strpos($settle, "\n    }\n") + 6);
        $update = strpos($settle, 'SET match_state');
        $evaluate = strpos($settle, '->evaluateInstallInTransaction(');
        $refuse = strpos($settle, "\$evaluated['install_conversion_id'] === null");
        $link = strpos($settle, 'SET conversion_id');
        self::assertIsInt($update);
        self::assertIsInt($evaluate);
        self::assertIsInt($refuse);
        self::assertIsInt($link);
        self::assertTrue($update < $evaluate && $evaluate < $refuse && $refuse < $link, 'settle() writes the state, evaluates, refuses a missing conversion, links it');
        // The require gate: the first statement of settle(), before the state is written.
        self::assertMatchesRegularExpression(
            '/^function settle\([^)]*\): array\s*\{\s*if \(\$state === MatchState::ATTRIBUTED\) \{\s*\[\$state, \$reason, \$clickId\] = \$this->integrityGate\(\$rowId, \$reason, \(int\) \$clickId\);\s*\}/',
            $settle,
            'settle() passes every attributed install through the Play Integrity gate before writing its state'
        );
        self::assertMatchesRegularExpression('/install_conversion_id\'\] === null\) \{\s*throw new/', $settle);

        // Its callers, and the transaction each runs in.
        $callers = [];
        foreach (self::tree() as $relative => $unused) {
            $source = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relative);
            $n = preg_match_all('/->settle\(/', $source);
            if ($n > 0) {
                $callers[$relative] = $n;
            }
        }
        ksort($callers);
        self::assertSame([
            'api/v3/Apps/Android/InstallIntake.php' => 1,
            'api/v3/Apps/Android/Integrity/IntegrityVerifier.php' => 1,
            'api/v3/Apps/Android/PendingClickSettler.php' => 1,
        ], $callers);
        $record = substr($intake, (int) strpos($intake, 'private function record('));
        $record = substr($record, 0, (int) strpos($record, "\n    }\n"));
        self::assertStringContainsString('$this->settle(', $record, 'the intake settles inside record()');
        self::assertMatchesRegularExpression('/\$work = fn \(\): array => \$this->record\(/', $intake);
        self::assertStringContainsString('$this->conn->transaction($work)', $intake);
        $settler = (string) file_get_contents(dirname(__DIR__, 3) . '/api/v3/Apps/Android/PendingClickSettler.php');
        self::assertMatchesRegularExpression('/\$work = function \(\) use \(\$installRowId\): array \{.*->intake->settle\(.*\};\s*try \{\s*\$done = \$this->conn->transaction\(\$work\);/s', $settler);
        $verifier = (string) file_get_contents(dirname(__DIR__, 3) . '/api/v3/Apps/Android/Integrity/IntegrityVerifier.php');
        // The row is locked by the read both reclassification paths share.
        self::assertMatchesRegularExpression('/\$work = function \(\) use \(\$installRowId, \$attempt, \$outcome\): array \{.*LockedInstall::read\(\$this->conn, \$installRowId\).*->intake->settle\(.*\};\s*try \{\s*\$done = \$this->conn->transaction\(\$work\);/s', $verifier);
        self::assertMatchesRegularExpression('/\$work = function \(\) use \(\$installRowId\): array \{.*LockedInstall::read\(\$this->conn, \$installRowId\).*->intake->settle\(/s', $settler);
        self::assertMatchesRegularExpression('/WHERE i\.install_row_id = \? LIMIT 1 FOR UPDATE\'?$/', \Api\V3\Apps\Android\LockedInstall::SQL, 'and that read locks the install row');
    }

    public function testEveryRedirectFallbackEmptiesTheInstallToken(): void
    {
        $root = dirname(__DIR__, 3);
        $sites = 0;
        foreach (SqlLiteralText::phpFiles($root) as $path) {
            $source = (string) file_get_contents($path);
            if (preg_match_all('/str_replace\(\s*([\'"])\[\[subid\]\]\1\s*,\s*([\'"])p202\2/', $source, $m, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            foreach ($m[0] as [$call, $at]) {
                $sites++;
                // The emptying sits within the same few lines as the
                // substitution it accompanies.
                $window = substr($source, max(0, $at - 400), 800);
                self::assertMatchesRegularExpression(
                    "/str_ireplace\\(\\s*'\\[\\[p202_install_token\\]\\]'\\s*,\\s*''/",
                    $window,
                    substr($path, strlen($root) + 1) . ': a fallback that fills [[subid]] with "p202" must empty [[p202_install_token]]'
                );
            }
        }
        self::assertGreaterThanOrEqual(5, $sites, 'the fallbacks (dl, lp, off x2, connect2) were all found');
    }

    public function testTheWriterScanSeesEveryPlantedShape(): void
    {
        foreach ([
            '$db->query("INSERT INTO 202_app_installs (user_id) VALUES (1)");' => 'insert',
            '$db->query("UPDATE `202_app_installs` SET match_state = \'attributed\'");' => 'update',
            '$sql = "UPDATE"; $sql .= " 202_app_installs SET trusted = 1"; $db->query($sql);' => 'update',
            '$db->query("delete from 202_app_installs where user_id = 1");' => 'delete',
            '$db->query("REPLACE INTO 202_app_installs SET user_id = 1");' => 'insert',
        ] as $code => $verb) {
            self::assertContains($verb, self::installWrites(SqlLiteralText::of("<?php\n" . $code)), $code);
        }
    }
}
