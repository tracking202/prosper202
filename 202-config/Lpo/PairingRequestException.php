<?php

declare(strict_types=1);

namespace Prosper202\Lpo;

use RuntimeException;

/**
 * A pairing call to the SaaS control plane failed. The exception message
 * carries the full technical detail (for error_log and cron output); UI
 * surfaces should show userMessage() instead — a plain-English explanation
 * with the next step, never a raw HTTP body.
 */
final class PairingRequestException extends RuntimeException
{
    public const KIND_TRANSPORT = 'transport';
    public const KIND_HTTP = 'http';
    public const KIND_BAD_RESPONSE = 'bad_response';

    /**
     * @param string $kind one of the KIND_* constants
     * @param string $url the request URL
     * @param ?int $httpStatus response status for KIND_HTTP, null otherwise
     * @param ?string $serverMessage a human-readable error the SaaS sent in
     *        its JSON body ({"error": ...} / {"message": ...}), if any
     */
    public function __construct(
        string $detail,
        private readonly string $kind,
        private readonly string $url,
        private readonly ?int $httpStatus = null,
        private readonly ?string $serverMessage = null,
    ) {
        parent::__construct($detail);
    }

    /**
     * What went wrong and what to do next, safe to render in the UI.
     */
    public function userMessage(): string
    {
        $host = (string) (parse_url($this->url, PHP_URL_HOST) ?: $this->url);

        if ($this->serverMessage !== null && $this->serverMessage !== '') {
            return $this->serverMessage;
        }

        if ($this->kind === self::KIND_TRANSPORT) {
            return "Couldn't reach the optimizer service at {$host}. Check that this server can make outbound HTTPS connections, then try again.";
        }

        return match (true) {
            $this->httpStatus === 401, $this->httpStatus === 403 =>
                "The optimizer service rejected this install's API key. Get a fresh Prosper202 Customer API key, then try connecting again.",
            $this->httpStatus === 404, $this->httpStatus === 405 =>
                "The optimizer service at {$host} doesn't support pairing yet. If you're self-hosting the optimizer, set BANDIT_SAAS_BASE_URL to your instance's URL in 202-config.php.",
            $this->httpStatus === 429 =>
                'The optimizer service is rate-limiting requests. Wait a minute, then try again.',
            $this->httpStatus !== null && $this->httpStatus >= 500 =>
                'The optimizer service hit an internal error. Try again in a few minutes.',
            default =>
                'The optimizer service sent an unexpected response. Try again — if it keeps happening, contact support.',
        };
    }
}
