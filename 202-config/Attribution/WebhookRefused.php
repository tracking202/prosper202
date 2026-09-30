<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/**
 * A webhook destination the guard will not send to, with the sentence that
 * says why. The sentence is written for the person who typed the URL: the
 * API returns it as the field error on `webhook_url`, and the dashboard
 * shows it under the field.
 */
final class WebhookRefused extends \RuntimeException
{
}
