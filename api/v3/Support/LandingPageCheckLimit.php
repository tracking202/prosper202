<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Exception\TooManyRequestsException;

/**
 * How often an API key may have the server fetch a landing page
 * (POST /landing-pages/{id}/check): PER_KEY fetches a WINDOW, a fixed
 * window from the key's first fetch in it (so up to twice PER_KEY across
 * one window's end and the next's start), counted by
 * the key the request authenticated with (Auth::apiKeyRef()), never by a
 * header the caller chooses. A page checked less than COOLDOWN seconds ago
 * is answered from that check without fetching (SetupCodeController), and
 * that answer costs nothing here.
 *
 * Fails closed: a count that cannot be read or written throws, and the
 * request is answered 500 with nothing fetched.
 */
final class LandingPageCheckLimit
{
    public const PER_KEY = 10;
    public const WINDOW = 60;
    public const COOLDOWN = 30;

    public function __construct(private readonly QuotaStore $store, private readonly string $keyRef)
    {
        if ($keyRef === '') {
            // An unnamed key would share one count with every other.
            throw new \LogicException('a landing page check is counted against the API key it came with');
        }
    }

    /**
     * Take one fetch from the key's window, or refuse with when to try again.
     *
     * @throws TooManyRequestsException over the limit; nothing is taken
     * @throws \RuntimeException when the count cannot be read or written
     */
    public function take(): void
    {
        $bucket = 'landing-page-check:' . $this->keyRef;
        $retryAfter = $this->store->reserveQuotaWindow($bucket, self::PER_KEY, self::WINDOW)['retry_after'];
        if ($retryAfter === null) {
            return;
        }

        throw new TooManyRequestsException(
            'This API key has had the ' . self::PER_KEY . ' landing page fetches it may have this minute:'
            . ' nothing was fetched. Try again in ' . $retryAfter . ' seconds.',
            $retryAfter,
            ['rate_limit' => ['limit' => self::PER_KEY, 'window_seconds' => self::WINDOW, 'per' => 'api_key']]
        );
    }
}
