<?php

declare(strict_types=1);

namespace Prosper202\Setup;

use JsonException;
use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;
use UnexpectedValueException;

/**
 * A landing page's split test, kept in 202_landing_page_split_tests (one row
 * per page): whether it is on, its variants with their weights, and whether
 * the variant replaces a C4 the visit already has. Setup › Landing Pages
 * saves it; the landing page script (tracking202/static/landing.php) picks
 * one variant per visitor by weight, keeps it for that visitor, shows it to
 * the page and sends it as the click's C4, so every report grouped by C4
 * shows each variant's clicks and conversions.
 *
 * A variant's name is what C4 stores, compared as the C4 lookup compares
 * (case folded): two names that differ only in case are one value, and are
 * refused. A weight of 0 keeps a variant listed with no visitor shown it.
 *
 * @phpstan-type Variant array{name: string, weight: int}
 * @phpstan-type Test array{status: int, overwrite_c4: int, variants: list<Variant>}
 */
final class LandingPageSplitTest
{
    public const MAX_VARIANTS = 10;
    public const NAME_MAX = 50;
    public const WEIGHT_MAX = 1000;
    private const NAME = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,49}$/D';

    public function __construct(private readonly Connection $conn)
    {
    }

    /** @return Test what a page with no row has: no test */
    public static function defaults(): array
    {
        return ['status' => 0, 'overwrite_c4' => 0, 'variants' => []];
    }

    /**
     * The test the landing page form posts: split_status and
     * split_overwrite_c4 (0 or 1), and split_variants, one variant a line as
     * its name and, after a space, its weight (1 when left out). What cannot
     * be stored as sent is refused in $errors under split_variants or the
     * switch's own name, never cut or dropped. A test that is off may keep
     * any list it can store; one that is on needs two variants and a weight
     * above 0.
     *
     * @param array<string, mixed> $post
     * @param array<string, string> $errors
     * @return Test
     */
    public static function fromForm(array $post, array &$errors): array
    {
        $flag = static function (string $field, string $what) use ($post, &$errors): int {
            $value = $post[$field] ?? '0';
            if (in_array($value, ['1', 'on'], true)) {
                return 1;
            }
            if ($value !== '0' && $value !== '') {
                $errors[$field] = 'Turn the split test\'s ' . $what . ' switch on or off.';
            }

            return 0;
        };
        $status = $flag('split_status', 'run');
        $overwrite = $flag('split_overwrite_c4', 'replace C4');

        $text = $post['split_variants'] ?? '';
        if (!is_string($text)) {
            $errors['split_variants'] = 'List the split test\'s variants as text, one a line.';

            return ['status' => $status, 'overwrite_c4' => $overwrite, 'variants' => []];
        }
        $variants = [];
        $seen = [];
        $problems = [];
        $lines = preg_split('/\r\n|\r|\n/', $text);
        if ($lines === false) {
            $errors['split_variants'] = 'The split test\'s variants could not be read.';

            return ['status' => $status, 'overwrite_c4' => $overwrite, 'variants' => []];
        }
        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $at = 'Line ' . ($index + 1) . ' (' . self::shown($line) . ')';
            $parts = preg_split('/[ \t]+/', $line);
            if ($parts === false || count($parts) > 2) {
                $problems[] = $at . ' is not a name and a weight.';
                continue;
            }
            $name = $parts[0];
            if (preg_match(self::NAME, $name) !== 1) {
                $problems[] = $at . ': a name is 1 to ' . self::NAME_MAX
                    . ' letters, digits, dots, dashes or underscores, starting with a letter or digit.';
                continue;
            }
            $weight = 1;
            if (isset($parts[1])) {
                if (preg_match('/^(0|[1-9][0-9]{0,3})$/D', $parts[1]) !== 1 || (int) $parts[1] > self::WEIGHT_MAX) {
                    $problems[] = $at . ': a weight is a whole number from 0 to ' . self::WEIGHT_MAX . '.';
                    continue;
                }
                $weight = (int) $parts[1];
            }
            $folded = strtolower($name);
            if (isset($seen[$folded])) {
                $problems[] = $at . ': ' . $seen[$folded] . ' is listed already, and C4 does not tell the two apart.';
                continue;
            }
            $seen[$folded] = $name;
            $variants[] = ['name' => $name, 'weight' => $weight];
        }
        if ($problems === [] && count($variants) > self::MAX_VARIANTS) {
            $problems[] = 'A split test has at most ' . self::MAX_VARIANTS . ' variants.';
        }
        if ($problems === [] && $status === 1) {
            if (count($variants) < 2) {
                $problems[] = 'A split test that is on needs at least two variants.';
            } elseif (array_sum(array_column($variants, 'weight')) < 1) {
                $problems[] = 'A split test that is on needs a variant with a weight above 0.';
            }
        }
        if ($problems !== []) {
            $errors['split_variants'] = implode(' ', $problems);
        }

        return ['status' => $status, 'overwrite_c4' => $overwrite, 'variants' => $variants];
    }

    /**
     * The variants as the form lists them, one "name weight" a line.
     *
     * @param list<Variant> $variants
     */
    public static function formText(array $variants): string
    {
        return implode("\n", array_map(
            static fn (array $v): string => $v['name'] . ' ' . $v['weight'],
            $variants
        ));
    }

    /**
     * The page's test, when the page is the user's; null when it has none
     * (or is another account's). A stored row that does not read is an
     * UnexpectedValueException, not "no test".
     *
     * @return Test|null
     */
    public function load(int $userId, int $landingPageId): ?array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT st.split_status, st.split_overwrite_c4, st.split_variants
               FROM ' . TableRegistry::LANDING_PAGE_SPLIT_TESTS . ' AS st
               JOIN 202_landing_pages AS lp ON lp.landing_page_id = st.landing_page_id AND lp.user_id = st.user_id
              WHERE st.landing_page_id = ? AND st.user_id = ?
              LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$landingPageId, $userId]);
        $row = $this->conn->fetchOne($stmt);

        return $row === null ? null : self::fromRow($row);
    }

    /**
     * What the landing page script runs for a page by its public id (lpip):
     * the variants a visitor can be shown, with their weights, and whether
     * the variant replaces a C4 the visit has. Null when the page has no test
     * running (none, off, or every weight 0). The row is the first page
     * with that public id, as every click-path read of a page takes, and its
     * test only when the row is the page's account's.
     *
     * @return array{variants: list<Variant>, overwriteC4: bool}|null
     */
    public function forPage(int $publicId): ?array
    {
        if ($publicId < 1) {
            return null;
        }
        $stmt = $this->conn->prepareRead(
            'SELECT landing_page_id, user_id
               FROM 202_landing_pages
              WHERE landing_page_id_public = ?
              ORDER BY landing_page_id
              LIMIT 1'
        );
        $this->conn->bind($stmt, 'i', [$publicId]);
        $page = $this->conn->fetchOne($stmt);
        if ($page === null) {
            return null;
        }
        $test = $this->load((int) $page['user_id'], (int) $page['landing_page_id']);

        return $test === null ? null : self::forScript($test);
    }

    /**
     * The test as the script takes it: only the variants a visitor can be
     * shown (weight above 0); null when it is off or has none.
     *
     * @param Test $test
     * @return array{variants: list<Variant>, overwriteC4: bool}|null
     */
    public static function forScript(array $test): ?array
    {
        if ($test['status'] !== 1) {
            return null;
        }
        $live = array_values(array_filter($test['variants'], static fn (array $v): bool => $v['weight'] > 0));

        return $live === [] ? null : ['variants' => $live, 'overwriteC4' => $test['overwrite_c4'] === 1];
    }

    /**
     * A stored row, read exactly: a switch that is neither 0 nor 1, or a
     * variant list that is not the list save() writes, names its column.
     *
     * @param array<string, mixed> $row
     * @return Test
     */
    public static function fromRow(array $row): array
    {
        $flag = static function (string $column) use ($row): int {
            $value = $row[$column] ?? null;
            if ($value === 0 || $value === '0') {
                return 0;
            }
            if ($value === 1 || $value === '1') {
                return 1;
            }
            throw new UnexpectedValueException('The stored split test\'s ' . $column . ' is not 0 or 1');
        };
        $status = $flag('split_status');
        $overwrite = $flag('split_overwrite_c4');
        $json = $row['split_variants'] ?? null;
        if (!is_string($json)) {
            throw new UnexpectedValueException('The stored split test\'s split_variants is not text');
        }
        try {
            $list = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnexpectedValueException(
                'The stored split test\'s split_variants is not JSON: ' . $e->getMessage(),
                0,
                $e
            );
        }
        if (!is_array($list) || !array_is_list($list) || count($list) > self::MAX_VARIANTS) {
            throw new UnexpectedValueException('The stored split test\'s split_variants is not a list of at most '
                . self::MAX_VARIANTS . ' variants');
        }
        $variants = [];
        $seen = [];
        foreach ($list as $i => $variant) {
            if (
                !is_array($variant) || array_keys($variant) !== ['name', 'weight']
                || !is_string($variant['name']) || preg_match(self::NAME, $variant['name']) !== 1
                || !is_int($variant['weight']) || $variant['weight'] < 0 || $variant['weight'] > self::WEIGHT_MAX
                || isset($seen[strtolower($variant['name'])])
            ) {
                throw new UnexpectedValueException('The stored split test\'s split_variants entry ' . $i
                    . ' is not a distinct {name, weight}');
            }
            $seen[strtolower($variant['name'])] = true;
            $variants[] = ['name' => $variant['name'], 'weight' => $variant['weight']];
        }

        return ['status' => $status, 'overwrite_c4' => $overwrite, 'variants' => $variants];
    }

    /**
     * Store the page's test, for a page of the user's only: the row is
     * selected from 202_landing_pages under the user's id, so another
     * account's page id writes nothing. No test (the defaults) creates no
     * row, and turns off a row that is there.
     *
     * @param Test $test
     */
    public function save(int $userId, int $landingPageId, array $test): void
    {
        try {
            $variants = json_encode($test['variants'], JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnexpectedValueException('The split test\'s variants could not be encoded', 0, $e);
        }
        $status = $test['status'] === 1 ? 1 : 0;
        $overwrite = $test['overwrite_c4'] === 1 ? 1 : 0;
        if ($test === self::defaults()) {
            $stmt = $this->conn->prepareWrite(
                'UPDATE ' . TableRegistry::LANDING_PAGE_SPLIT_TESTS . '
                    SET split_status = 0, split_overwrite_c4 = 0, split_variants = ?, split_updated_at = ?
                  WHERE landing_page_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, 'siii', [$variants, time(), $landingPageId, $userId]);
            $this->conn->executeUpdate($stmt);

            return;
        }
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO ' . TableRegistry::LANDING_PAGE_SPLIT_TESTS . '
                    (landing_page_id, user_id, split_status, split_overwrite_c4, split_variants, split_updated_at)
             SELECT lp.landing_page_id, lp.user_id, ?, ?, ?, ?
               FROM 202_landing_pages AS lp
              WHERE lp.landing_page_id = ? AND lp.user_id = ?
             ON DUPLICATE KEY UPDATE
                    user_id = VALUES(user_id),
                    split_status = VALUES(split_status),
                    split_overwrite_c4 = VALUES(split_overwrite_c4),
                    split_variants = VALUES(split_variants),
                    split_updated_at = VALUES(split_updated_at)'
        );
        $this->conn->bind($stmt, 'iisiii', [$status, $overwrite, $variants, time(), $landingPageId, $userId]);
        $this->conn->executeUpdate($stmt);
    }

    /** A line as an error message shows it: at most 40 characters, valid UTF-8. */
    private static function shown(string $line): string
    {
        $line = mb_scrub($line, 'UTF-8');

        return mb_strlen($line, 'UTF-8') > 40 ? mb_substr($line, 0, 40, 'UTF-8') . '…' : $line;
    }
}
