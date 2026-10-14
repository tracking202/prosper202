<?php

/**
 * Runs connect2.php's replaceTrackerPlaceholders() as shipped, lifted out
 * of the bootstrap, against a stand-in _mysqli_query() that counts the
 * click reads and answers one row.
 *
 * argv: <url> <click id> [<the read's answer: row | none | fails>]
 * stdout: {"url": the filled URL, "reads": statements run}, or
 *         {"error_page": the failure the error page was handed, "reads": …}
 */

declare(strict_types=1);

[, $url, $clickId, $answer] = $argv + [null, '', '0', 'row'];

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/../../Config/fixtures/lift-function.php';

$connect2 = __DIR__ . '/../../../202-config/connect2.php';
foreach (['replaceTrackerPlaceholders', 'replaceClickPlaceholders', 'p202ClickUrlTokens', 'replaceTokens'] as $name) {
    eval(lift_function($connect2, $name));
}

$reads = 0;
// Evaluated, as the other runners declare their stand-ins.
$db = eval(<<<'PHP'
    function _mysqli_query($db, $sql)
    {
        $GLOBALS['reads']++;

        return new class {
            public function fetch_assoc(): array|null|false
            {
                return match ($GLOBALS['answer']) {
                    'none' => null,
                    'fails' => false,
                    default => ['c1' => 'from-the-row', 'click_cpc' => '0.37000', 'ppc_account_id' => '6'],
                };
            }
        };
    }

    function record_mysql_error($db, $sql = null, ?string $error = null): never
    {
        echo json_encode(['error_page' => $error, 'reads' => $GLOBALS['reads']], JSON_THROW_ON_ERROR);
        exit;
    }

    function p202ProvenInstallToken($clickId): string
    {
        return 'token-' . $clickId;
    }

    return new class {
        public function real_escape_string(string $value): string
        {
            return addslashes($value);
        }
    };
    PHP);

echo json_encode(['url' => replaceTrackerPlaceholders($db, $url, $clickId), 'reads' => $reads], JSON_THROW_ON_ERROR);
