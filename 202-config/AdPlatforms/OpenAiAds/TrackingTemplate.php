<?php

declare(strict_types=1);

namespace Prosper202\AdPlatforms\OpenAiAds;

use Prosper202\Setup\ChatGptAdsSource;

/**
 * The tracking template pushed to a ChatGPT Ads campaign when none is given:
 * the one Get Links hands out for the ChatGPT Ads source
 * (ChatGptAdsSource::TEMPLATE), so the two cannot drift apart.
 */
final class TrackingTemplate
{
    /** OpenAI's limit on landing_page_configuration.query_string_template. */
    public const MAX_LENGTH = ChatGptAdsSource::TEMPLATE_MAX_LENGTH;

    public static function default(): string
    {
        return ChatGptAdsSource::TEMPLATE;
    }
}
