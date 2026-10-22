<?php

/**
 * The other side of a deadlock with a re-click of <click> (RtrReClickRetryIntegrationTest),
 * in a process of its own. In one transaction it writes enough undo that the
 * server picks the re-click as the deadlock's victim, locks the click's
 * 202_clicks_advance row, which the re-click upserts after it has locked the
 * click, and prints "held"; after <pause> milliseconds it asks for the
 * click's lock as MysqlConversionLedger::lockClick() takes it, prints
 * "locked" once it has it, and rolls everything back.
 *
 * argv: <click> <user> <pause>
 * env:  P202_TEST_DB_HOST/PORT/USER/PASS/NAME
 */

declare(strict_types=1);

[, $click, $user, $pause] = $argv + [null, '0', '0', '1500'];
$click = (int) $click;
$user = (int) $user;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli(
        (string) (getenv('P202_TEST_DB_HOST') ?: '127.0.0.1'),
        (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
        (string) (getenv('P202_TEST_DB_PASS') ?: ''),
        (string) getenv('P202_TEST_DB_NAME'),
        (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
    );
    $db->query('SET SESSION innodb_lock_wait_timeout = 20');
    if (!$db->begin_transaction()) {
        throw new RuntimeException('could not begin');
    }
    $values = [];
    for ($i = 1; $i <= 300; $i++) {
        $values[] = "(0, 0, $user, $i, $i, 0)";
    }
    $db->query('INSERT INTO 202_dirty_hours (ppc_account_id, aff_campaign_id, user_id, click_time_from, click_time_to,'
        . ' landing_page_id) VALUES ' . implode(', ', $values));
    $advance = $db->query("SELECT click_id FROM 202_clicks_advance WHERE click_id = $click FOR UPDATE");
    if (count($advance->fetch_all()) !== 1) {
        echo "no 202_clicks_advance row of click $click\n";
        exit(1);
    }
    echo "held\n";
    flush();
    usleep((int) $pause * 1000);
    $db->query("SELECT click_id FROM 202_clicks WHERE click_id = $click AND user_id = $user LIMIT 1 FOR UPDATE")
        ->fetch_all();
    echo "locked\n";
    usleep(300_000);
    if (!$db->rollback()) {
        throw new RuntimeException('could not roll back');
    }
    echo "done\n";
} catch (Throwable $e) {
    echo 'THREW ' . get_class($e) . ' ' . $e->getCode() . ': ' . $e->getMessage() . "\n";
    exit(1);
}
