<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/**
 * The host resolved to no address from this server: refused for now, like
 * any refusal, but not a verdict on the URL, since a resolver that failed
 * this time may answer the next. The postback sender retries it; the export
 * webhooks treat it as any WebhookRefused.
 */
final class WebhookUnresolved extends WebhookRefused
{
}
