<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android;

use Prosper202\Database\Connection;
use Prosper202\Identity\ClickIdentity;
use Prosper202\Identity\CustomerId;
use Prosper202\Identity\IdentityKeys;
use Throwable;

/**
 * Links an install's click to the signed customer id the app reported
 * (plan §4.3, §6.2): the cross-device join, the app side of `cust` +
 * `cust_sig` on a tracking link.
 *
 * Only a click this install *proved* is linked — an attributed, trusted
 * install, whose token's MAC verified and whose timing was plausible. A
 * refuted install names no click worth linking, and an organic one names
 * none. The signature is checked against the account's linking key before
 * anything is written, so an id the operator's server did not sign links
 * nothing (it stays in the install's raw body only).
 *
 * Identity is an enrichment, as on the click path (ClickIdentity): it runs
 * after the install's or the events' transaction has committed, in its own,
 * and a failure is logged and answered `not_linked` — never a failed
 * request, since what the request recorded is already durable. Linking the
 * same claim again is harmless (IdentityGraph::attachClick is idempotent
 * for a click and its signals), which is what makes a replay safe.
 *
 * The answer the device gets is one word: `linked`, `unverified` (the
 * signature is not the operator's), `no_click` (nothing this install proved
 * to link to) or `not_linked` (the campaign's identity capture is off, or
 * linking failed and was logged).
 */
final class InstallCustomerLink
{
    public const LINKED = 'linked';
    public const UNVERIFIED = 'unverified';
    public const NO_CLICK = 'no_click';
    public const NOT_LINKED = 'not_linked';

    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * @param array<string, mixed> $install a 202_app_installs row, read after its transaction committed
     */
    public function link(array $install, CustomerClaim $claim): string
    {
        $clickId = isset($install['click_id']) ? (int) $install['click_id'] : 0;
        $attributed = (string) ($install['match_state'] ?? '') === MatchState::ATTRIBUTED->value;
        $trusted = $install['trusted'] !== null && (int) $install['trusted'] === 1;
        if (!$attributed || !$trusted || $clickId <= 0) {
            return self::NO_CLICK;
        }
        $userId = (int) $install['user_id'];
        try {
            $linkKey = (new IdentityKeys($this->conn))->forUser($userId)['link'];
        } catch (Throwable $e) {
            error_log('p202 android customer: install ' . (string) $install['install_uuid']
                . ' could not read the linking key: ' . $e->getMessage());

            return self::NOT_LINKED;
        }
        if (!CustomerId::verify($linkKey, $claim->canonical(), $claim->signature)) {
            return self::UNVERIFIED;
        }
        // attachToStoredClick re-reads the click's owner and its campaign's
        // identity setting, verifies the signature again under the same key,
        // and logs (never throws) a failure to link.
        $identity = ClickIdentity::customerOnly([
            'cust' => $claim->id,
            'cust_type' => $claim->type,
            'cust_sig' => $claim->signature,
        ]);

        return $identity->attachToStoredClick($this->conn, $clickId) !== null ? self::LINKED : self::NOT_LINKED;
    }
}
