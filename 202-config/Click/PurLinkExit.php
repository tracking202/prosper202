<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Database\Connection;
use Prosper202\Conversion\Ledger\MatchMethod;
use Prosper202\Conversion\Ledger\MysqlConversionLedger;
use Prosper202\Conversion\MysqlConversionRepository;

/**
 * Records a PurLink exit for the click and landing page named by its proof,
 * in page interaction order (recordOrdered()): a delayed retry must not
 * change an advanced page's newer offer selection.
 */
final class PurLinkExit
{
    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * Record a browser exit in page interaction order. Reports with a smaller
     * sequence are stale; an exact retry is harmless. The proof's click lock
     * serializes the ordering row and the click rewrite with conversions.
     * 'converted': the click-out is recorded, but the click had already
     * converted, so its offer was not applied.
     *
     * @return 'recorded'|'converted'|'duplicate'|'stale'|'conflict'|'refused'
     */
    public function recordOrdered(string $proof, mixed $campaignPublicId, mixed $sequence, int $now, string $key): string
    {
        $claims = PurLinkProof::verify($proof, PurLinkProof::EXIT, $now, $key);
        $order = PublicId::parse($sequence);
        if ($claims === null || $order === null || $order > 2_147_483_647) {
            return 'refused';
        }
        $work = fn (): string => (new MysqlConversionLedger($this->conn))->clickTransaction(
            $claims['click_id'],
            $claims['user_id'],
            function (?array $click) use ($claims, $campaignPublicId, $order): string {
                if ($click === null) {
                    return 'refused';
                }
                // All reads happen after the click lock: a later committed
                // report or re-click is visible before routing this one.
                $route = $this->resolveClaims($claims, $campaignPublicId);
                if ($route === null) {
                    return 'refused';
                }
                $stmt = $this->conn->prepareWrite(
                    'SELECT user_id, landing_page_id, sequence, campaign_id FROM 202_purlink_exit_order WHERE click_id = ?'
                );
                $this->conn->bind($stmt, 'i', [$claims['click_id']]);
                $prior = $this->conn->fetchOne($stmt);
                if ($prior !== null) {
                    if ((int) $prior['user_id'] !== $claims['user_id'] || (int) $prior['landing_page_id'] !== $claims['landing_page_id']) {
                        return 'refused';
                    }
                    if ($order < (int) $prior['sequence']) {
                        return 'stale';
                    }
                    if ($order === (int) $prior['sequence']) {
                        return (int) $prior['campaign_id'] === $route['campaign_id'] ? 'duplicate' : 'conflict';
                    }
                }
                $converted = (int) $click['click_lead'] === 1;
                $accepted = (new MysqlClickRepository($this->conn))->recordExitInTransaction(new ClickExit(
                    $claims['user_id'],
                    $claims['click_id'],
                    // Never rewrite an older visit of an already converted
                    // click, including those with mixed lead flags.
                    offer: $converted ? null : new OfferRoute($route['campaign_id'], $route['payout']),
                    cloaking: 0,
                    landingPageId: $claims['landing_page_id'],
                ));
                if (!$accepted) {
                    return 'refused';
                }
                // The fence lives as long as the longest-lived proof it saw.
                $stmt = $this->conn->prepareWrite(
                    'INSERT INTO 202_purlink_exit_order (click_id,user_id,landing_page_id,sequence,campaign_id,expires_at)'
                    . ' VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE sequence=VALUES(sequence),'
                    . ' campaign_id=VALUES(campaign_id), expires_at=GREATEST(expires_at, VALUES(expires_at))'
                );
                $this->conn->bind($stmt, 'iiiiii', [
                    $claims['click_id'], $claims['user_id'], $claims['landing_page_id'], $order,
                    $route['campaign_id'], $claims['expires'],
                ]);
                $this->conn->executeUpdate($stmt);
                return $converted ? 'converted' : 'recorded';
            }
        );
        try {
            return $work();
        } catch (\Throwable $error) {
            if (!Connection::isRetryableLockError($error)) {
                throw $error;
            }
            return $work();
        }
    }

    /** A SubID for the offer, or null when the proof or offer is refused. */
    public function subid(string $proof, mixed $campaignPublicId, int $now, string $key): ?string
    {
        $route = $this->resolve($proof, $campaignPublicId, $now, $key);
        if ($route === null || $route['campaign_public_id'] < 1) {
            return null;
        }

        return PurLinkSubid::issue($proof, $route['campaign_public_id'], $now, $key);
    }

    /**
     * Verify a signed SubID against its click's latest visit. Without a lock:
     * convert() checks the page and account again under the click lock.
     *
     * @return array{click_id: int, landing_page_id: int, user_id: int, campaign_public_id: int}|null
     */
    public function claims(mixed $subid, string $key): ?array
    {
        $parsed = PurLinkSubid::parse($subid);
        if ($parsed === null) {
            return null;
        }
        $stmt = $this->conn->prepareWrite(
            'SELECT c.user_id, c.landing_page_id FROM ' . LatestVisit::of('?') . ' AS c'
        );
        $this->conn->bind($stmt, 'i', [$parsed['click_id']]);
        $click = $this->conn->fetchOne($stmt);
        if ($click === null || !PurLinkSubid::check($parsed, (int) $click['landing_page_id'], (int) $click['user_id'], $key)) {
            return null;
        }

        return [
            'click_id' => $parsed['click_id'],
            'landing_page_id' => (int) $click['landing_page_id'],
            'user_id' => (int) $click['user_id'],
            'campaign_public_id' => $parsed['campaign_public_id'],
        ];
    }

    /**
     * @return array{claims: array{click_id: int, landing_page_id: int, user_id: int, expires: int},
     *     campaign_id: int, campaign_public_id: int, payout: string}|null
     */
    private function resolve(string $proof, mixed $campaignPublicId, int $now, string $key): ?array
    {
        $claims = PurLinkProof::verify($proof, PurLinkProof::EXIT, $now, $key);
        if ($claims === null) {
            return null;
        }
        return $this->resolveClaims($claims, $campaignPublicId);
    }

    /**
     * @param array{click_id: int, landing_page_id: int, user_id: int, expires?: int} $claims
     * @return array{claims: array{click_id: int, landing_page_id: int, user_id: int, expires?: int},
     *     campaign_id: int, campaign_public_id: int, payout: string}|null
     */
    private function resolveClaims(array $claims, mixed $campaignPublicId, bool $deletedRecordsCount = false): ?array
    {
        $campaign = null;
        if ($campaignPublicId !== null && $campaignPublicId !== '') {
            $campaign = PublicId::parse($campaignPublicId);
            if ($campaign === null) {
                return null;
            }
        }

        $stmt = $this->conn->prepareWrite(
            'SELECT lp.landing_page_type, lp.aff_campaign_id FROM ' . LatestVisit::of('?') . ' AS c'
                . ' JOIN 202_landing_pages AS lp ON lp.landing_page_id = c.landing_page_id'
                . ' AND lp.user_id = c.user_id AND (? = 1 OR lp.landing_page_deleted = 0)'
                . ' WHERE lp.landing_page_id = ? AND lp.user_id = ?'
        );
        $this->conn->bind($stmt, 'iiii', [$claims['click_id'], $deletedRecordsCount ? 1 : 0, $claims['landing_page_id'], $claims['user_id']]);
        $page = $this->conn->fetchOne($stmt);
        if ($page === null) {
            return null;
        }

        if ((int) $page['landing_page_type'] === 1) {
            if ($campaign === null) {
                return null;
            }
            $where = 'aff_campaign_id_public = ?';
            $id = $campaign;
        } else {
            $where = 'aff_campaign_id = ?';
            $id = (int) $page['aff_campaign_id'];
        }
        $stmt = $this->conn->prepareWrite(
            'SELECT aff_campaign_id, aff_campaign_id_public, aff_campaign_payout FROM 202_aff_campaigns'
                . ' WHERE ' . $where . ' AND user_id = ?' . ($deletedRecordsCount ? '' : ' AND aff_campaign_deleted = 0') . ' LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$id, $claims['user_id']]);
        $offer = $this->conn->fetchOne($stmt);
        if ($offer === null || ($campaign !== null && (int) $offer['aff_campaign_id_public'] !== $campaign)) {
            return null;
        }

        return [
            'claims' => $claims,
            'campaign_id' => (int) $offer['aff_campaign_id'],
            'campaign_public_id' => (int) $offer['aff_campaign_id_public'],
            'payout' => (string) $offer['aff_campaign_payout'],
        ];
    }

    /**
     * Route and record a conversion together under the click lock. The writer
     * must join this transaction and throw on failure; its post-commit work
     * belongs to the caller, after this method returns.
     *
     * @template T
     * @param callable(int, int, int, string): T $write receives account,
     *        click, offer campaign ID and offer payout. The writer must use
     *        the offer for the conversion even when the click already converted
     *        under another campaign.
     * @return T|null null when the SubID or its route is refused
     */
    public function convert(string $subid, string $key, callable $write): mixed
    {
        $claims = $this->claims($subid, $key);
        if ($claims === null) {
            return null;
        }
        $work = fn (): mixed => (new MysqlConversionLedger($this->conn))->clickTransaction(
            $claims['click_id'],
            $claims['user_id'],
            function (?array $click) use ($claims, $write): mixed {
                if ($click === null) {
                    return null;
                }
                // The SubID's MAC already proves the route: a page or campaign
                // deleted since must not lose the network's later postbacks.
                $route = $this->resolveClaims($claims, $claims['campaign_public_id'], true);
                if ($route === null) {
                    return null;
                }
                $recorded = (new MysqlClickRepository($this->conn))->recordExitInTransaction(new ClickExit(
                    $claims['user_id'],
                    $claims['click_id'],
                    // A converted click can have older visits whose lead flag
                    // has not been backfilled. Keep every visit's route;
                    // the conversion row still names this SubID's offer.
                    offer: (int) $click['click_lead'] === 1
                        ? null : new OfferRoute($route['campaign_id'], $route['payout']),
                    cloaking: 0,
                    landingPageId: $claims['landing_page_id'],
                    deletedRecordsCount: true,
                ));
                if (!$recorded) {
                    return null;
                }

                return $write($claims['user_id'], $claims['click_id'], $route['campaign_id'], $route['payout']);
            }
        );
        // A deadlock rolls back the entire route and conversion. Retry that
        // entire transaction, as the regular conversion writer does.
        try {
            return $work();
        } catch (\Throwable $error) {
            if (!Connection::isRetryableLockError($error)) {
                throw $error;
            }
            return $work();
        }
    }

    /**
     * Record a plain ledger conversion using the verified SubID's offer.
     * Caller-supplied click and campaign IDs cannot override the SubID.
     * An explicit payout is preserved; otherwise the offer's payout is used.
     *
     * @param array<string, mixed> $data conversion details
     * @return array<string, mixed>|null null when the SubID or route is refused
     */
    public function recordConversion(
        string $subid,
        string $key,
        array $data,
        MatchMethod $matchedBy,
        ?callable $clickSideUpdate = null,
    ): ?array
    {
        $writer = new MysqlConversionRepository($this->conn);
        $claims = $this->claims($subid, $key);
        if ($claims === null) {
            return null;
        }
        $result = $this->convert(
            $subid,
            $key,
            static function (
                int $user,
                int $click,
                int $campaign,
                string $payout
            ) use (
                $writer,
                $data,
                $matchedBy,
                $clickSideUpdate
            ): array {
                $data['click_id'] = $click;
                $data['campaign_id'] = $campaign;
                if (
                    (!array_key_exists('payout', $data) || $data['payout'] === null || $data['payout'] === '')
                    && empty($data['reversal'])
                ) {
                    $data['payout'] = $payout;
                }

                return $writer->recordInTransaction($user, $data, $matchedBy, $clickSideUpdate);
            }
        );
        if ($result === null) {
            return null;
        }
        // convert() returned only after its outer transaction committed.
        $writer->afterRecordInTransaction($claims['user_id'], $result);

        return $result;
    }
}
