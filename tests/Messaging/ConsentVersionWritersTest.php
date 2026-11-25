<?php

declare(strict_types=1);

namespace Tests\Messaging;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * The receiver keeps the highest consent version it has seen for a user, so
 * a version the install assigns has to be above every one it assigned
 * before, a restored backup included. ConsentPolicy assigns it as
 * GREATEST(consent_version + 1, UNIX_TIMESTAMP()), and
 * ConsentSyncIntegrationTest runs each of its writers; this holds every other
 * file to naming the three version columns of 202_users_pref only with a
 * reason, so a second writer cannot assign a version some other way.
 *
 * The name is the heuristic: a column built from parts, or a file that is
 * not PHP, is not seen.
 */
final class ConsentVersionWritersTest extends TestCase
{
    private const COLUMNS = '/\b(?:consent_version|consent_acked_version|analytics_revoked_version)\b/i';

    private const USERS_PREF_WRITE = '/\b(?:UPDATE|INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO)\s+`?202_users_pref\b/i';

    /** Files that may name a version column, with what they do with it. */
    private const NAMED = [
        '202-config/Messaging/ConsentPolicy.class.php' => 'the one writer of the 202_users_pref versions',
        '202-config/Messaging/MessagingService.class.php' => 'reads them, and stamps its own events and snapshot with the version they were captured under',
        '202-config/User/ServedPreferences.php' => 'served by GET /users/{id}/preferences, never written there',
        '202-config/Database/Tables/UserTables.php' => 'the column definitions',
        '202-config/Database/Tables/CoreTables.php' => 'the messaging tables\' capture stamp',
        '202-config/functions-upgrade.php' => 'the attributes table\'s marker for the reconcile, which adds the columns and writes no value',
    ];

    /** Of NAMED, the files that may also write 202_users_pref. */
    private const WRITES_USERS_PREF = [
        '202-config/Messaging/ConsentPolicy.class.php',
        '202-config/functions-upgrade.php',
    ];

    public function testOnlyTheListedFilesNameTheConsentVersions(): void
    {
        $naming = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (preg_match(self::COLUMNS, $source) === 1) {
                $naming[] = $path;
            }
        }

        $unlisted = array_values(array_diff($naming, array_keys(self::NAMED)));
        $this->assertSame([], $unlisted, 'a consent version of 202_users_pref is assigned only by ConsentPolicy, as'
            . ' GREATEST(consent_version + 1, UNIX_TIMESTAMP()); list the file here with what it does with the column');

        $gone = array_values(array_diff(array_keys(self::NAMED), $naming));
        $this->assertSame([], $gone, 'listed files that no longer name a version column: take them off the list');
    }

    public function testNoOtherNamingFileWritesThePreferencesRow(): void
    {
        $files = SourceScan::phpFiles();
        foreach (array_keys(self::NAMED) as $path) {
            if (in_array($path, self::WRITES_USERS_PREF, true)) {
                continue;
            }
            $this->assertArrayHasKey($path, $files);
            $this->assertSame(0, preg_match(self::USERS_PREF_WRITE, $files[$path]),
                "$path names a consent version and writes 202_users_pref: its writes go through ConsentPolicy");
        }
    }

    public function testTheUpgradeAssignsNoVersion(): void
    {
        $source = SourceScan::phpFiles()['202-config/functions-upgrade.php'];
        preg_match_all('/[^\n]*' . trim(self::COLUMNS, '/i') . '[^\n]*/i', $source, $lines);
        foreach ($lines[0] as $line) {
            $this->assertDoesNotMatchRegularExpression('/\b(?:consent_version|consent_acked_version|analytics_revoked_version)`?\s*=/i', $line,
                'the upgrade assigns a consent version, which only ConsentPolicy may do');
        }
    }

    public function testTheCheckSeesAWriterOutsideTheList(): void
    {
        $planted = "UPDATE `202_users_pref` SET `consent_version` = ? WHERE user_id = ?";
        $this->assertSame(1, preg_match(self::COLUMNS, $planted));
        $this->assertSame(1, preg_match(self::USERS_PREF_WRITE, $planted));
        $this->assertSame(1, preg_match(self::USERS_PREF_WRITE, 'REPLACE INTO 202_users_pref (user_id) VALUES (1)'));
        $this->assertSame(1, preg_match(self::USERS_PREF_WRITE, 'insert ignore into `202_users_pref` (`user_id`)'));
        $this->assertSame(0, preg_match(self::COLUMNS, 'SET `consent_versions` = 1'));
    }
}
