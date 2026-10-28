<?php

declare(strict_types=1);

namespace Api\V3\Apps;

use Api\V3\Exception\ValidationException;

/**
 * The store link a campaign sends its clicks to (plan §5.1, §5.6), for
 * either platform, and whether a campaign's offer URL is one.
 *
 *  - Android: the Play listing with the install token in its referrer,
 *    `…details?id=<package>&referrer=p202%3D[[p202_install_token]]`. The
 *    redirect expands the token per click; the SDK reads it back from the
 *    Install Referrer, which is what ties the install to the click.
 *  - iOS: the App Store listing, `https://apps.apple.com/app/id<id>`.
 *    Nothing rides in it: SKAdNetwork and AdAttributionKit attribute the
 *    install to the ad network's signed impression, and Apple's postback
 *    names the app, not the click.
 */
final class StoreLink
{
    public const INSTALL_TOKEN = '[[p202_install_token]]';

    public static function template(string $platform, string $appKey): string
    {
        return match ($platform) {
            AppIdentity::ANDROID => 'https://play.google.com/store/apps/details?id=' . rawurlencode($appKey)
                . '&referrer=p202%3D' . self::INSTALL_TOKEN,
            AppIdentity::IOS => 'https://apps.apple.com/app/id' . $appKey,
            default => throw new \InvalidArgumentException('StoreLink::template(): unknown platform ' . $platform),
        };
    }

    /** Whether the URL is a store link for this app (AppIdentity's reading of it). */
    public static function namesApp(string $url, string $platform, string $appKey): bool
    {
        try {
            $identity = AppIdentity::fromStoreLink($url);
        } catch (ValidationException) {
            return false;
        }

        return $identity->platform === $platform && $identity->appKey === $appKey;
    }

    /**
     * Whether an Android store link carries the install token where the SDK
     * reads it: a `p202` entry, holding exactly the token, inside the
     * `referrer` parameter. Anywhere else (a top-level parameter, which Play
     * drops; a second, unencoded `&p202=`) the install arrives with no click.
     */
    public static function carriesInstallToken(string $url): bool
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return false;
        }
        foreach (explode('&', $query) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (rawurldecode($name) !== 'referrer') {
                continue;
            }
            foreach (explode('&', rawurldecode($value)) as $inner) {
                [$innerName, $innerValue] = array_pad(explode('=', $inner, 2), 2, '');
                if ($innerName === 'p202' && strcasecmp($innerValue, self::INSTALL_TOKEN) === 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
