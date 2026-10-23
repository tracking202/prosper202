<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

use Prosper202\Database\Connection;

/**
 * How a conversion's click was found: what tied the request to the click
 * the row credits. ConversionSource says what sent the conversion; this
 * says whether that sender named the click or the click was inferred, so a
 * same-IP guess never reads as a click-id match.
 *
 * Every value is one a path produces (MatchMethodEndpointsInstanceTest drives
 * the pixels and postbacks; MatchMethodIntegrationTest the other writers):
 *  - click_id: the request named the click — a subid or sid parameter,
 *    ClickBank's tracking code, the click id of an API call or of an upload
 *    line, or an app install's signed token;
 *  - cookie: a tracking cookie in the visitor's browser named it (the
 *    campaign's tracking202subid_a_<id>, or tracking202subid);
 *  - visitor_id: the landing-page script's own visitor id (p202lpid), on
 *    the click it was last seen on (WebEvents::clickForVisitor());
 *  - same_ip: nothing named a click, and it is the account's latest click
 *    from the visitor's address (Prosper202\Click\LastClickFromAddress);
 *  - unknown: a row from before 1.9.79, the legacy baseline that carries a
 *    pre-ledger value, or a goal reached by an event stored before 1.9.79.
 *    It is the column's default, and the one spelling every reader uses for
 *    "not recorded" (CLAUDE.md #25).
 */
enum MatchMethod: string
{
    case CLICK_ID = 'click_id';
    case COOKIE = 'cookie';
    case VISITOR_ID = 'visitor_id';
    case SAME_IP = 'same_ip';
    case UNKNOWN = 'unknown';

    /** A short human label for the breakdown and the reports. */
    public function label(): string
    {
        return match ($this) {
            self::CLICK_ID => 'Click id',
            self::COOKIE => 'Cookie',
            self::VISITOR_ID => 'Visitor id',
            self::SAME_IP => 'Same IP',
            self::UNKNOWN => 'Unknown',
        };
    }

    /**
     * A stored match_method as the enum. A value that is not one of the
     * list is a row nothing should have written, and is refused naming
     * where it was read rather than read as some other method.
     *
     * @throws LedgerIntegrityException
     */
    public static function fromStored(mixed $stored, string $where): self
    {
        $method = is_string($stored) ? self::tryFrom($stored) : null;
        if ($method === null) {
            throw new LedgerIntegrityException(
                $where . ' has match_method ' . var_export($stored, true) . ', which is not a match method'
            );
        }

        return $method;
    }

    /** @return list<string> every value, in order */
    public static function values(): array
    {
        return array_map(static fn (self $m): string => $m->value, self::cases());
    }

    /**
     * Prepare an INSERT that names match_method, or, while the 1.9.79
     * upgrade has not added the column ($withoutColumn, the same INSERT
     * without it), that one: the pixels and postbacks do not ask whether the
     * database is upgraded, so this code can run first, and a conversion is
     * not refused for want of a column that only describes it. The upgrade
     * gives such a row 'unknown', which no reader takes for a match. Any
     * other failure is the caller's.
     *
     * @return array{0: \mysqli_stmt, 1: bool} the statement, and whether it names the column
     */
    public static function prepareInsert(Connection $conn, string $sql, string $withoutColumn): array
    {
        try {
            return [$conn->prepareWrite($sql), true];
        } catch (\RuntimeException $e) {
            if (!self::columnMissing($e)) {
                throw $e;
            }
        }
        error_log('p202 conversions: the 1.9.79 upgrade has not added match_method yet, so this row is written'
            . ' without how its click was found, and reads as unknown. Run the upgrade. (' . strtok(trim($sql), '(') . ')');

        return [$conn->prepareWrite($withoutColumn), false];
    }

    /** Whether $e, or one it wraps, is the server's 1054 for a match_method column. */
    public static function columnMissing(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (Connection::isMysqlError($current, 1054, '') && str_contains($current->getMessage(), "'match_method'")) {
                return true;
            }
        }

        return false;
    }
}
