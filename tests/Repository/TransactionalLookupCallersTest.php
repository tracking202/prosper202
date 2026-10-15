<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * Two lookups write more than one row and open a transaction for it:
 * findOrCreateIp() for an IPv6 address and findOrCreateVariableSet().
 * mysqli's begin_transaction() inside an open transaction commits it
 * (CLAUDE.md #13), so a caller inside its own transaction would have its
 * partial writes committed and its rollback undo nothing. Inside one opened
 * by Connection::transaction() the lookups refuse at run time
 * (outermostTransaction()); one opened with begin_transaction() or
 * autocommit(false) directly they cannot see. So every call outside the
 * repositories is listed with what puts it outside a transaction; a new one
 * is read for that before it is listed, and a removed one leaves the list.
 *
 * Read from tokens: `->name(` and `?->name(`, in any case. It counts calls
 * per file and does not read where they sit: a listed call moved into a raw
 * transaction in the same file keeps its count and passes. Not seen either:
 * a call through a variable method name or call_user_func().
 */
final class TransactionalLookupCallersTest extends TestCase
{
    private const METHODS = ['findorcreateip', 'findorcreatevariableset'];

    /** file => [method => calls], each outside any transaction for the reason given */
    private const CALLERS = [
        // The sign-in (202-login.php, its one caller), which opens no transaction.
        '202-config/User/SignInRecord.php' => ['findorcreateip' => 1],
        // The error page (the click path's call and the admin pages'), after
        // p202RecordMysqlErrorRow() ended the caller's transaction.
        '202-config/functions-shared.php' => ['findorcreateip' => 2],
        // Before recordClick() opens the click's transaction.
        'tracking202/redirect/dl.php' => ['findorcreateip' => 1, 'findorcreatevariableset' => 1],
        'tracking202/static/record_adv.php' => ['findorcreateip' => 1, 'findorcreatevariableset' => 1],
        'tracking202/static/record_simple.php' => ['findorcreateip' => 1, 'findorcreatevariableset' => 1],
        // Before recordClick() or recordReClick() opens the click's transaction.
        'tracking202/redirect/rtr.php' => ['findorcreateip' => 1],
    ];

    public function testEveryCallerOfATransactionalLookupIsListed(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $file => $source) {
            if (str_starts_with($file, '202-config/Repository/')) {
                continue;
            }
            $calls = self::calls($source);
            if ($calls !== []) {
                $found[$file] = $calls;
            }
        }
        ksort($found);
        $listed = self::CALLERS;
        ksort($listed);

        self::assertSame($listed, $found, 'A call of a lookup that opens a transaction, unlisted or gone.'
            . ' Show that the caller is outside any transaction, then list it with why.');
    }

    public function testTheScanReadsEachShape(): void
    {
        $code = '<?php $r->findOrCreateIp($a); $r?->FINDORCREATEVARIABLESET($v); '
            . '$r->findOrCreateIpv6($a); findOrCreateIp($a); $r->findOrCreateIp; // $r->findOrCreateIp($a)';

        self::assertSame(['findorcreateip' => 1, 'findorcreatevariableset' => 1], self::calls($code));
    }

    /** @return array<string, int> method => calls */
    private static function calls(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $out = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }
            $name = strtolower($token[1]);
            $before = $tokens[$i - 1] ?? null;
            $member = is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
            if ($member && in_array($name, self::METHODS, true) && ($tokens[$i + 1] ?? null) === '(') {
                $out[$name] = ($out[$name] ?? 0) + 1;
            }
        }
        ksort($out);

        return $out;
    }
}
