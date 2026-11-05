<?php

declare(strict_types=1);

namespace Prosper202\Setup;

use InvalidArgumentException;
use Prosper202\Click\PublicId;

/** Builds the direct campaign URL used by a landing-page PurLink. */
final class PurLinkTemplate
{
    private function __construct()
    {
    }

    /** The legacy advanced-page template carries the public offer ID as acip. */
    public static function advanced(string $campaignUrl, mixed $campaignPublicId): string
    {
        $id = PublicId::parse($campaignPublicId);
        if ($id === null) {
            throw new InvalidArgumentException('The campaign public ID must be a positive whole number');
        }
        self::validateUrl($campaignUrl);
        $fragmentAt = strpos($campaignUrl, '#');
        $beforeFragment = $fragmentAt === false ? $campaignUrl : substr($campaignUrl, 0, $fragmentAt);
        $fragment = $fragmentAt === false ? '' : substr($campaignUrl, $fragmentAt);
        $queryAt = strpos($beforeFragment, '?');
        if ($queryAt !== false) {
            foreach (explode('&', substr($beforeFragment, $queryAt + 1)) as $part) {
                $name = explode('=', $part, 2)[0];
                if (strcasecmp(urldecode($name), 'acip') === 0) {
                    throw new InvalidArgumentException('The campaign URL already has an acip parameter');
                }
            }
        }
        $separator = $queryAt === false
            ? '?'
            : (str_ends_with($beforeFragment, '?') || str_ends_with($beforeFragment, '&') ? '' : '&');

        return $beforeFragment . $separator . 'acip=' . $id . $fragment;
    }

    /** A simple-page template is the campaign URL as entered. */
    public static function simple(string $campaignUrl): string
    {
        self::validateUrl($campaignUrl);

        return $campaignUrl;
    }

    private static function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        if (
            !is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || $parts['host'] === '' || isset($parts['user']) || isset($parts['pass'])
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1
        ) {
            throw new InvalidArgumentException('The campaign URL must be an absolute HTTP or HTTPS URL');
        }
    }
}
