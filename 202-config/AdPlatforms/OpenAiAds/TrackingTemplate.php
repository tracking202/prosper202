<?php

declare(strict_types=1);

namespace Prosper202\AdPlatforms\OpenAiAds;

/**
 * The tracking template pushed to a ChatGPT Ads campaign when none is given:
 * the one Get Links hands out for the ChatGPT Ads source
 * (Prosper202\Setup\ChatGptAdsSource::TEMPLATE) when this version has it,
 * else the same string, as spec section 6 writes it. The one place to swap.
 */
final class TrackingTemplate
{
    /** OpenAI's limit on landing_page_configuration.query_string_template. */
    public const MAX_LENGTH = 2048;
    private const SPEC = 'utm_source=chatgpt&utm_medium=paid&utm_campaign={campaign_id}&utm_content={ad_id}'
        . '&campaign_id={campaign_id}&ad_id={ad_id}&oppref={oppref}';

    public static function default(): string
    {
        $source = 'Prosper202\\Setup\\ChatGptAdsSource';
        if (class_exists($source) && defined($source . '::TEMPLATE')) {
            $template = constant($source . '::TEMPLATE');
            if (is_string($template) && $template !== '') {
                return $template;
            }
        }

        return self::SPEC;
    }
}
