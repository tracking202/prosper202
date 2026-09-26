<?php

declare(strict_types=1);

namespace Tests\Apps\Android\Integrity;

use PHPUnit\Framework\TestCase;
use Tests\Support\SqlLiteralText;

/**
 * "No install is left queued for a verdict its credential can no longer
 * give" (plan §5.11), over the whole tree. Every path that removes a Play
 * Integrity credential is listed here, with what it does about the installs
 * still waiting on it first:
 *
 *  - DELETE /apps/{id}/integrity-credential (AppIntegrityController, through
 *    IntegrityCredentialStore::clear()) refuses while any is queued;
 *  - deleting the registration (AppRegistrationsController::beforeDelete())
 *    settles them (UnverifiableInstalls) before its DELETE;
 *  - the user purge (AppDataPurge::purgeUser()) deletes the installs before
 *    the credentials.
 *
 * A new path that deletes the table, reaches it through the registry
 * constant, or calls the store's clear() fails here and has to decide what
 * happens to the queue. The order checks are positional within each method
 * (they pin the shipped shape against an accidental reorder); the behaviour
 * itself is IntegrityIntegrationTest's and IntegritySettingsIntegrationTest's.
 */
final class CredentialDeletionSettlesTheQueueTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 4);
    }

    /** @return array<string, string> relative path => source */
    private static function sources(): array
    {
        static $sources = null;
        if ($sources !== null) {
            return $sources;
        }
        $sources = [];
        foreach (SqlLiteralText::phpFiles(self::root()) as $path) {
            $sources[substr($path, strlen(self::root()) + 1)] = (string) file_get_contents($path);
        }

        return $sources;
    }

    /** The body of one method, from its signature to its closing brace at class-member indentation. */
    private static function method(string $relative, string $name): string
    {
        $source = self::sources()[$relative];
        $at = strpos($source, 'function ' . $name . '(');
        self::assertIsInt($at, $relative . ' has no ' . $name . '()');
        $body = substr($source, $at);

        return substr($body, 0, (int) strpos($body, "\n    }\n"));
    }

    public function testEveryPathThatRemovesACredentialIsKnown(): void
    {
        self::assertGreaterThan(300, count(self::sources()), 'the walk found too few files to be the whole tree');
        $deletes = [];
        $constant = [];
        $clears = [];
        foreach (self::sources() as $relative => $source) {
            if (str_starts_with($relative, 'tests/')) {
                continue;
            }
            if (preg_match('/\bDELETE\b[^;]{0,40}?\bFROM\s+`?202_app_integrity_credentials`?/i', SqlLiteralText::of($source)) === 1) {
                $deletes[] = $relative;
            }
            if (str_contains($source, 'APP_INTEGRITY_CREDENTIALS')) {
                $constant[] = $relative;
            }
            if (str_contains($source, 'IntegrityCredentialStore') && preg_match('/->clear\(/', $source) === 1) {
                $clears[] = $relative;
            }
        }
        sort($deletes);
        sort($constant);
        sort($clears);
        self::assertSame(['api/v3/Apps/AppDataPurge.php', 'api/v3/Controllers/AppRegistrationsController.php'], $deletes);
        self::assertSame([
            '202-config/Database/Schema/TableRegistry.php',
            '202-config/Database/Tables/AppTables.php',
            'api/v3/Apps/Android/Integrity/IntegrityCredentialStore.php',
        ], $constant, 'the table is reached through the registry constant only by its store');
        self::assertSame(['api/v3/Controllers/AppIntegrityController.php'], $clears);
        self::assertSame(1, preg_match_all('/\bDELETE\b/', SqlLiteralText::of(self::sources()['api/v3/Apps/Android/Integrity/IntegrityCredentialStore.php'])));
    }

    public function testEachSettlesOrRefusesBeforeItDeletes(): void
    {
        $clear = self::method('api/v3/Controllers/AppIntegrityController.php', 'clearCredential');
        $count = strpos($clear, '$this->installsAwaitingAVerdict($registrationId)');
        $refuse = strpos($clear, 'if ($waiting > 0) {');
        $delete = strpos($clear, '$this->credentials->clear(');
        self::assertIsInt($count);
        self::assertIsInt($refuse);
        self::assertIsInt($delete);
        self::assertTrue($count < $refuse && $refuse < $delete, 'clearCredential() counts the queue and refuses before it clears');
        self::assertMatchesRegularExpression('/if \(\$waiting > 0\) \{\s*throw new ConflictException\(/', $clear);
        self::assertSame(1, substr_count($clear, '->clear('));

        $delete = self::method('api/v3/Controllers/AppRegistrationsController.php', 'beforeDelete');
        $settle = strpos($delete, '->settleForDeletedRegistration($this->userId, $registrationId, ');
        $gone = strpos($delete, 'DELETE FROM 202_app_integrity_credentials');
        self::assertIsInt($settle);
        self::assertIsInt($gone);
        self::assertLessThan($gone, $settle, 'the registration delete settles the queue before the credential goes');
        // deleteRecord() runs beforeDelete() and the row delete; the change
        // records follow the commit (Controller::deleteRecord(), #167).
        self::assertMatchesRegularExpression('/\$this->transaction\(fn \(\): array => \$this->deleteRecord\(\$id\)\)/',
            self::method('api/v3/Controllers/AppRegistrationsController.php', 'delete'), 'and both commit with the registration delete');

        $purge = self::method('api/v3/Apps/AppDataPurge.php', 'purgeUser');
        $installs = strpos($purge, "'DELETE FROM 202_app_installs WHERE user_id = ?'");
        $credentials = strpos($purge, "'DELETE FROM 202_app_integrity_credentials WHERE user_id = ?'");
        self::assertIsInt($installs);
        self::assertIsInt($credentials);
        self::assertLessThan($credentials, $installs, 'the purge deletes the installs before their credentials');
    }
}
