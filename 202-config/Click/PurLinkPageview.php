<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Api\V3\Apps\Android\InstallTokenKey;
use Prosper202\Identity\RequestSignals;

/** Optional pageview capabilities for a click already committed by this request. */
final class PurLinkPageview
{
    /** One day permits deferred pixels without leaving a long-lived exit capability. */
    public const LIFETIME = 86400;

    private function __construct()
    {
    }

    /**
     * Whether a landing page snippet keeps PurLinks on: on unless it names
     * p202_purlink, and then only for 1. Any other value (0, off, false, a
     * list) turns them off, as p202_consent reads (RequestSignals::consentGiven()).
     *
     * @param array<string, mixed> $get
     */
    public static function requestedBy(array $get): bool
    {
        if (!array_key_exists('p202_purlink', $get)) {
            return true;
        }

        return is_string($get['p202_purlink']) && trim($get['p202_purlink']) === '1';
    }

    /**
     * The caller invokes this only after its click transaction commits. If
     * signing is unavailable, tracking continues with its redirect fallback.
     * Proofs never appear in a request URL or a cookie.
     *
     * @param array<string, mixed> $request
     * @param callable(): ?string $key read the installation key only for an eligible pageview
     */
    public static function script(
        array $request,
        bool $trackingAllowed,
        int $clickId,
        int $landingPageId,
        int $userId,
        int $now,
        callable $key,
    ): string {
        // The exit proof does what the tracker's redirect does, so it needs
        // only consent. The pixel proof fires third-party pixels in the page,
        // so the account's privacy setting must also allow full tracking.
        if (($request['p202_purlink'] ?? null) !== '1' || !RequestSignals::consentGiven($request)) {
            return '';
        }
        try {
            $secret = $key();
            if ($secret === null) {
                throw new \RuntimeException('installation token key is missing');
            }
            $payload = [
                'exitProof' => PurLinkProof::issue(PurLinkProof::EXIT, $clickId, $landingPageId, $userId, $now, self::LIFETIME, $secret),
            ];
            if ($trackingAllowed) {
                // The page may keep this visitor's first subid (t202UseSubidOrig).
                $payload['keepSubid'] = true;
                $payload['pixelProof'] = PurLinkProof::issue(PurLinkProof::PIXELS, $clickId, $landingPageId, $userId, $now, self::LIFETIME, $secret);
            }
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        } catch (\Throwable $error) {
            error_log('PurLink pageview capability unavailable: ' . $error->getMessage());
            return '';
        }

        return "try { if (typeof window.p202PurLinkPageview === 'function') window.p202PurLinkPageview(" . $json . "); } catch (e) {}\n";
    }

    /** @param array<string, mixed> $request */
    public static function forDatabase(array $request, bool $trackingAllowed, int $clickId, int $landingPageId, int $userId, \mysqli $db): string
    {
        return self::script($request, $trackingAllowed, $clickId, $landingPageId, $userId, time(), static fn (): ?string => InstallTokenKey::load($db));
    }
}
