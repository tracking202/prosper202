<?php

declare(strict_types=1);

namespace Prosper202\Conversion;

use Prosper202\Click\LatestVisit;
use Prosper202\Conversion\Ledger\ConsentWithheld;
use Prosper202\Database\Connection;

/**
 * Whether a conversion may be told to an ad platform: the one answer every
 * route asks, the destinations (DestinationQueue::deliver()), the traffic
 * source's browser pixels and Meta Purchase (TrafficSourcePixels::fire())
 * and its server postbacks (NotificationOutbox). A visitor who refused
 * consent (p202_consent) on the conversion's request, on a conversion it
 * replaced, or on any visit, conversion or goal event of its click is told
 * to none of them (ConsentWithheld). An answer that could not be read
 * allows nothing (CLAUDE.md #11).
 */
final class AdPlatformConsent
{
    public const REFUSED = 'withheld: the visitor refused consent (p202_consent)';
    public const UNREAD = 'withheld: whether the visitor refused consent could not be read';

    private function __construct(private readonly ?string $withheld)
    {
    }

    public static function given(): self
    {
        return new self(null);
    }

    /** $where: where the refusal was made, as ConsentWithheld::refusalOf() says it. */
    public static function refused(string $where): self
    {
        return new self(self::REFUSED . ' ' . $where);
    }

    public static function unread(string $why): self
    {
        return new self(self::UNREAD . ' (' . $why . ')');
    }

    /**
     * A row that selected ConsentWithheld::partsSql(); a part missing from it
     * is a refusal.
     *
     * @param array<string, mixed> $row
     */
    public static function fromParts(array $row): self
    {
        $where = ConsentWithheld::refusalOf($row);

        return $where === null ? self::given() : self::refused($where);
    }

    /**
     * Conversion $convId's answer. $requestRefused is the refusal of the
     * request the caller is answering, which stands whatever is stored, and
     * is all there is to read while the 1.9.80 upgrade has not added the
     * refusals' storage (a refusal could not be kept before it either).
     * Read on the write connection: the row was just written, and a
     * replica that has not caught up would hold everything back. With
     * $inTransaction the read is inside the caller's transaction, whose lock
     * errors are its owner's (#34).
     */
    public static function ofConversion(Connection $conn, int $userId, int $convId, bool $requestRefused, bool $inTransaction = false): self
    {
        if ($requestRefused) {
            return self::refused('on the conversion\'s request');
        }
        $sql = 'SELECT ' . ConsentWithheld::partsSql() . ' FROM 202_conversion_logs AS cl WHERE cl.conv_id = ? AND cl.user_id = ?';
        try {
            $stmt = $conn->prepareWrite($sql);
            $conn->bind($stmt, 'ii', [$convId, $userId]);
            $row = $conn->fetchOne($stmt);
        } catch (\Throwable $e) {
            if ($inTransaction && ($conn->isRollbackOnly() || Connection::isRetryableLockError($e))) {
                throw $e;
            }
            if (ConsentWithheld::storageMissing($e)) {
                return self::given();
            }
            error_log('conversion ' . $convId . ': whether its visitor refused consent could not be read; nothing is sent'
                . ' to its traffic source: ' . $e->getMessage());

            return self::unread('the conversion could not be read');
        }
        if ($row === null) {
            return self::unread('conversion ' . $convId . ' is not the account\'s');
        }

        return self::fromParts($row);
    }

    /**
     * Click $clickId's answer, for an outcome that wrote no conversion: its
     * visits, conversions and goal events (ConsentRefusal), and the
     * request's own, as ofConversion() reads them (on the write
     * connection too: the refusal may be the request's own write).
     */
    public static function ofClick(Connection $conn, int $userId, int $clickId, bool $requestRefused): self
    {
        if ($requestRefused) {
            return self::refused('on the conversion\'s request');
        }
        $sql = "SELECT '0' AS refused_request, '0' AS refused_replaced, " . ConsentWithheld::visitRefusedSql()
            . ' AS refused_visit FROM ' . LatestVisit::of('?') . ' AS cl WHERE cl.user_id = ?';
        try {
            $stmt = $conn->prepareWrite($sql);
            $conn->bind($stmt, 'ii', [$clickId, $userId]);
            $row = $conn->fetchOne($stmt);
        } catch (\Throwable $e) {
            if (ConsentWithheld::storageMissing($e)) {
                return self::given();
            }
            error_log('click ' . $clickId . ': whether its visitor refused consent could not be read; nothing is sent'
                . ' to its traffic source: ' . $e->getMessage());

            return self::unread('the click could not be read');
        }
        if ($row === null) {
            return self::unread('click ' . $clickId . ' is not the account\'s');
        }

        return self::fromParts($row);
    }

    public function allows(): bool
    {
        return $this->withheld === null;
    }

    /** Why nothing may be sent, or null when it may. */
    public function reason(): ?string
    {
        return $this->withheld;
    }
}
