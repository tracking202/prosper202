<?php

declare(strict_types=1);

namespace Tests\Database;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Schema\SchemaDefinition;

/**
 * Every text column in a UNIQUE or PRIMARY key either compares exactly or
 * says why folding is what it means (CLAUDE.md #17: a key must be
 * injective).
 *
 * The tables default to utf8mb4_general_ci, under which `tx:A-1` and
 * `tx:a-1` are one value. For a key someone else chose — a network's
 * transaction id, a caller's idempotency key, a billing system's
 * subscription id — that turns a second, different record into a replay of
 * the first: the ledger answered a real sale `duplicate`, and LTV dropped a
 * revenue event, with a UNIQUE key doing exactly what it was declared to do.
 * A column in a key must therefore declare `COLLATE utf8mb4_bin` (or be a
 * binary type), or be listed below with the reason its comparison should
 * fold case — so a new key column is a decision, not a default.
 */
final class UniqueKeyCollationTest extends TestCase
{
    /**
     * table.column => why a case-insensitive comparison is right (or
     * harmless) for it.
     */
    private const FOLDS_ON_PURPOSE = [
        // Values this code writes in one case only, so folding cannot merge two of them.
        '202_app_registrations.platform' => 'a fixed word (ios, android)',
        '202_app_registrations.app_token' => 'lowercase hex (AppToken, bin2hex)',
        '202_app_postbacks.dedupe_hash' => 'lowercase hex (sha1 in PostbackReceiver::dedupeHash)',
        '202_app_skan_encodings.coarse_value' => 'a fixed word (low, medium, high)',
        '202_attribution_models.model_slug' => 'lowercased by ModelRepository::slugFor()',
        '202_goal_events.subject_type' => 'a fixed word (click, install)',
        '202_goal_outcomes.subject_type' => 'a fixed word (click, install)',
        '202_goal_progress.subject_type' => 'a fixed word (click, install)',
        '202_goal_subjects.subject_type' => 'a fixed word (click, install)',
        '202_identity_signals.signal_type' => 'a fixed word (SignalType)',
        '202_identity_signals.signal_hash' => 'lowercase hex (hash_hmac)',
        '202_identity_observations.signal_type' => 'a fixed word (SignalType)',
        '202_identity_observations.signal_hash' => 'lowercase hex (hash_hmac)',
        '202_customer_aliases.alias_type' => 'one of MysqlCustomerRepository::ALIAS_TYPES, normalized on write',
        '202_customer_fields.field_key' => 'lowercased on write (MysqlCustomerFieldRepository)',
        '202_offer_recommendations.surface' => 'a fixed word',
        '202_sync_jobs.job_uuid' => 'a generated hex job id',
        '202_sync_audit.job_uuid' => 'a generated hex job id',
        // Names people type, where case is not identity.
        '202_users.user_name' => 'sign-in names are case-insensitive by design',
        '202_companies.normalized_name' => 'normalized for matching by design',
        '202_companies.domain' => 'DNS names are case-insensitive',
        // Random or service-issued ids where a case twin is not a real
        // input: folding loses entropy a brute force could not use.
        '202_sessions.session_id' => 'a random PHP session id',
        '202_messaging_conversations.external_id' => 'issued by the messaging service',
        '202_messaging_messages.external_id' => 'issued by the messaging service',
        // Legacy tracking tables keyed per click or per label.
        '202_google.gclid' => 'per click, legacy',
        '202_bing.msclkid' => 'per click, legacy',
        '202_facebook.fbclid' => 'per click, legacy',
        '202_pixel_types.pixel_type' => 'a fixed label, legacy',
        '202_variable_sets2.variables' => 'legacy',
    ];

    /** @return list<SchemaDefinition> */
    private static function definitions(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];
        foreach (glob($root . '/202-config/Database/Tables/*Tables.php') ?: [] as $file) {
            $class = 'Prosper202\\Database\\Tables\\' . basename($file, '.php');
            foreach ($class::getDefinitions() as $definition) {
                $out[] = $definition;
            }
        }

        return $out;
    }

    /** @return array<string, string> table.column => column SQL, for every text column in a UNIQUE or PRIMARY key */
    private static function keyTextColumns(): array
    {
        $found = [];
        foreach (self::definitions() as $definition) {
            // Read from the statement itself: some legacy definitions carry
            // items (an unnamed INDEX) that SchemaReconciler refuses to parse.
            $sql = $definition->createStatement;
            $columns = [];
            foreach (preg_split('/\R/', $sql) ?: [] as $line) {
                if (preg_match('/^\s*(`([^`]+)`\s+.*?),?\s*$/', $line, $c) === 1) {
                    $columns[$c[2]] = $c[1];
                }
            }
            $keyed = [];
            preg_match_all('/(?:UNIQUE\s+(?:KEY|INDEX)\s*(?:`[^`]*`)?|PRIMARY\s+KEY)\s*\(((?:[^()]|\([^)]*\))*)\)/i', $sql, $keys);
            foreach ($keys[1] as $list) {
                $keyed[] = $list;
            }
            foreach ($keyed as $list) {
                preg_match_all('/`([^`]+)`/', $list, $names);
                foreach ($names[1] as $name) {
                    $sql = $columns[$name] ?? null;
                    self::assertNotNull($sql, $definition->tableName . ': key column ' . $name . ' is not a column');
                    if (preg_match('/^`[^`]+`\s+(?:var)?char\b|^`[^`]+`\s+(?:tiny|medium|long)?text\b/i', $sql) === 1) {
                        $found[$definition->tableName . '.' . $name] = $sql;
                    }
                }
            }
        }
        ksort($found);

        return $found;
    }

    public function testTheScanFindsTheKeysItIsAbout(): void
    {
        $found = self::keyTextColumns();
        $this->assertArrayHasKey('202_conversion_logs.dedupe_key', $found, 'the scan reads UNIQUE keys');
        $this->assertArrayHasKey('202_goal_events.event_id', $found);
        $this->assertArrayHasKey('202_identity_signals.signal_hash', $found, 'and PRIMARY keys');
        $this->assertGreaterThan(20, count($found));
    }

    public function testEveryKeyColumnComparesExactlyOrSaysWhyItFolds(): void
    {
        $folding = [];
        foreach (self::keyTextColumns() as $column => $sql) {
            if (preg_match('/\bCOLLATE\s+utf8mb4_bin\b/i', $sql) === 1 || preg_match('/\bCHARACTER\s+SET\s+(?:binary|ascii\s+COLLATE\s+ascii_bin)\b/i', $sql) === 1) {
                continue;
            }
            if (!isset(self::FOLDS_ON_PURPOSE[$column])) {
                $folding[] = $column;
            }
        }
        $this->assertSame([], $folding, 'these key columns fold case under the table collation; declare COLLATE utf8mb4_bin, '
            . 'or add them to FOLDS_ON_PURPOSE with the reason folding is right');
    }

    public function testTheAllowlistNamesOnlyFoldingKeyColumns(): void
    {
        $found = self::keyTextColumns();
        foreach (array_keys(self::FOLDS_ON_PURPOSE) as $column) {
            $this->assertArrayHasKey($column, $found, $column . ' is no longer a text column in a key; remove it from FOLDS_ON_PURPOSE');
            $this->assertDoesNotMatchRegularExpression('/\bCOLLATE\s+utf8mb4_bin\b/i', $found[$column], $column . ' compares exactly now; remove it from FOLDS_ON_PURPOSE');
        }
    }
}
