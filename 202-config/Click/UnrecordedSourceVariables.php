<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Repository\Cache\RowCache;

/**
 * Says in the server log that a click is stored without the custom variable
 * values it came with, because its traffic source account names a traffic
 * source in another user's account: a click reads only the variables of a
 * source in its own user's account (ClickPathReads), so it reads none of
 * that source's. Account › Settings lists those sources and moves or splits
 * them (Prosper202\Setup\TrafficSourceOwners); nothing here changes what a
 * click records.
 *
 * A line is written only when all of these hold: the click read no variable
 * of its own, its traffic source account names a source in another user's
 * account, and the request carries a value under at least one of that
 * source's live variables. A source of the account's own with no variables,
 * an account that names no source, and a click that came with no value lose
 * nothing and say nothing.
 *
 * With a cache, one line an hour for each traffic source account: every
 * click on such an account is the same finding, and a busy one would write
 * millions of lines a day. Without one (no memcache), every such click.
 * The line names ids and a count, never a value or the visitor.
 */
final readonly class UnrecordedSourceVariables
{
    public const int SAID_FOR_SECONDS = 3600;

    private const KEY_PREFIX = 'p202-unrecorded-variables-';

    /** @param RowCache|null $said where "said this hour" is kept; null says it for every click */
    public function __construct(
        private ClickPathReads $reads,
        private ?RowCache $said = null,
        private string $namespace = '',
    ) {
        if ($said !== null && $namespace === '') {
            throw new \InvalidArgumentException('A kept "said" needs the install\'s namespace');
        }
    }

    /**
     * After a click script has read its request for the source's variables:
     * $read is what TrackerVariables::pairs() gave it. Never throws: the
     * visitor goes on, and a check that could not be made is logged as that.
     *
     * @param list<array{string, int}> $read
     * @param array<array-key, mixed> $request the click's query
     */
    public function note(int $userId, int $ppcAccountId, array $read, array $request): void
    {
        if ($read !== [] || $userId < 1 || $ppcAccountId < 1) {
            return;
        }
        try {
            $line = $this->line($userId, $ppcAccountId, $request);
        } catch (\Throwable $e) {
            error_log('p202 click: whether traffic source account ' . $ppcAccountId . ' of user ' . $userId
                . ' names another user\'s traffic source could not be read (' . $e->getMessage()
                . '); the click is stored as it was read');

            return;
        }
        if ($line !== null) {
            error_log($line);
        }
    }

    /** @param array<array-key, mixed> $request */
    private function line(int $userId, int $ppcAccountId, array $request): ?string
    {
        $source = $this->reads->sourceOfAnotherUser($userId, $ppcAccountId);
        if ($source === null || $source['source_user_id'] === null) {
            return null;
        }
        $came = 0;
        foreach (TrackerVariables::pairs($source) as [$parameter]) {
            if (ClickRequest::text($request, $parameter) !== '') {
                $came++;
            }
        }
        if ($came === 0) {
            return null;
        }
        if ($this->said !== null) {
            $key = self::KEY_PREFIX . hash('sha256', $this->namespace . "\0" . $userId . "\0" . $ppcAccountId);
            if ($this->said->get($key) !== null) {
                return null;
            }
            $this->said->set($key, ['said' => '1']);
        }

        return 'p202 click: a click on traffic source account ' . $ppcAccountId . ' of user ' . $userId
            . ' came with ' . $came . ' custom variable ' . ($came === 1 ? 'value' : 'values')
            . ' and is stored without ' . ($came === 1 ? 'it' : 'them') . ': the account names traffic source '
            . (int) $source['ppc_network_id'] . ', which is in user ' . (int) $source['source_user_id']
            . '\'s account, and a click records only the variables of a source in its own user\'s account.'
            . ' Every click on this account loses them until the source is moved back or split. The cron job'
            . ' splits it by itself within the hour where it can; where it cannot, the source waits for a'
            . ' decision on Account › Settings, "Traffic sources in another user\'s account" (p202 system'
            . ' traffic-source-owners).'
            . ($this->said !== null
                ? ' Said once an hour for each traffic source account, not for each click.'
                : '');
    }
}
