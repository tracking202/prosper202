<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Auth;
use Api\V3\AuthException;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\ValidationException;
use Api\V3\Exception\WriteCommittedException;
use Api\V3\Support\PayloadKeys;
use Api\V3\Support\QueryInt;
use Api\V3\Support\RequestFlag;
use Prosper202\Database\Connection;
use Prosper202\Setup\TrafficSourceOwners;
use Prosper202\Setup\TrafficSourceRepairRefused;

/**
 * Traffic sources in one user's account that another user's traffic source
 * accounts name, which Setup › Traffic Sources' rename could leave before
 * 1.9.79 (Prosper202\Setup\TrafficSourceOwners says how, and what else the
 * same rows can mean):
 *
 * - GET  /system/traffic-source-owners         the report
 * - POST /system/traffic-source-owners/repair  move one back, or all the
 *                                              report can name a user for
 * - POST /system/traffic-source-owners/split   give each user whose accounts
 *                                              name one a copy of their own
 *
 * Behind Admin and access_to_settings, as the rest of /system's install
 * settings (api/v3/index.php; TrafficSourceOwnersRoutesTest): the report
 * names every user's sources. A repair moves a source out of one user's
 * account into another's, so the caller must also be one who may act on both
 * users (Auth::requireSelfOrMayManageUser(), Account › Users' rule): only
 * user 1 moves a source into or out of user 1's account. A split asks the
 * same of the user the source is in and of each user who gets a copy.
 *
 * Account › Settings runs this controller too, for the signed-in user
 * (Auth::forSignedInUser()) behind the same two checks
 * (202-config/functions-traffic-source-owners-ui.php).
 */
final class TrafficSourceOwnersController
{
    /** mediumint unsigned: 202_ppc_networks.ppc_network_id and 202_users.user_id. */
    private const MAX_ID = 16777215;

    public function __construct(
        private readonly \mysqli $db,
        private readonly Auth $auth
    ) {
    }

    /**
     * Without $counts, no clicks or conversions: Account › Settings' notice.
     *
     * @return array{data: array<string, mixed>}
     */
    public function report(bool $counts = true): array
    {
        $sources = $this->guard(fn (): array => $this->owners()->report($counts));
        $repairable = count(array_filter($sources, static fn (array $s): bool => $s['repair_to_user_id'] !== null));

        return ['data' => [
            'sources' => $sources,
            'repairable' => $repairable,
            'ambiguous' => count($sources) - $repairable,
        ]];
    }

    /**
     * {"ppc_network_id": N} moves one source to the user the report names;
     * with "to_user_id" it moves it to that user, who must be one whose
     * accounts name it (the one way to move an ambiguous source).
     * "owner_user_id" and "repair_to_user_id", the report's values sent back,
     * refuse the move when the source is no longer in that account or would
     * no longer go to that user.
     * {"all": true} moves every source the report names a user for, each in
     * a transaction of its own; the rest are answered as skipped.
     *
     * @param array<string, mixed> $payload
     * @return array{data: array{repaired: list<array<string, mixed>>, skipped: list<array<string, mixed>>}}
     */
    public function repair(array $payload): array
    {
        $keys = ['ppc_network_id', 'to_user_id', 'owner_user_id', 'repair_to_user_id'];
        PayloadKeys::refuseUnknown($payload, [...$keys, 'all'], 'a traffic source repair', [
            'dry_run' => 'is not taken: GET /system/traffic-source-owners says what a repair would do',
        ]);
        $all = RequestFlag::param($payload, 'all', false);
        if ($all) {
            $named = array_values(array_intersect($keys, array_keys($payload)));
            if ($named !== []) {
                $why = 'is not taken with all: true, which repairs every source GET /system/traffic-source-owners'
                    . ' names a repair_to_user_id for as it reads it then. Send each source by itself to name'
                    . ' its user, or what the report showed you.';
                throw new ValidationException('all names no source', array_fill_keys($named, $why));
            }

            return ['data' => $this->repairAll()];
        }
        if (!array_key_exists('ppc_network_id', $payload)) {
            throw new ValidationException('ppc_network_id is required', [
                'ppc_network_id' => 'is required: the traffic source to move back (or send {"all": true})',
            ]);
        }
        $sourceId = QueryInt::required($payload, 'ppc_network_id', 1, self::MAX_ID, 'the traffic source to move back');
        $toUserId = QueryInt::param($payload, 'to_user_id', 0, 1, self::MAX_ID, 'the user to move it to');
        $named = $toUserId === 0 ? null : $toUserId;
        if ($named !== null && array_key_exists('repair_to_user_id', $payload)) {
            throw new ValidationException('to_user_id and repair_to_user_id', [
                'repair_to_user_id' => 'is not taken with to_user_id, which names the user: send one of the two',
            ]);
        }
        // A source's user_id can be 0 (no user), which the report shows as its owner.
        $shownOwner = array_key_exists('owner_user_id', $payload)
            ? QueryInt::required($payload, 'owner_user_id', 0, self::MAX_ID, 'the owner the report showed')
            : null;
        $shownTo = array_key_exists('repair_to_user_id', $payload)
            ? QueryInt::required($payload, 'repair_to_user_id', 1, self::MAX_ID, 'the user the report showed')
            : null;

        $owners = $this->owners();
        $plan = $this->refused(fn (): array => $owners->plan($sourceId, $named, $shownOwner, $shownTo));
        $this->mayMove($plan['from'], $plan['to']);
        $actor = $this->auth->userId();
        $moved = $this->refused(fn (): array => $owners->repair($sourceId, $plan['from'], $plan['to'], $named, $actor));

        return ['data' => ['repaired' => [$moved], 'skipped' => []]];
    }

    /**
     * {"ppc_network_id": N} leaves the source in the account it is in and
     * gives each user the report lists under named_by a copy of their own,
     * onto which their accounts naming it move: the way to clear a source
     * that accounts of more than one user name, which no move can.
     * "owner_user_id" and "copy_to_user_ids", the report's owner and
     * named_by users sent back, refuse the split when the source is in
     * another account now, or would give copies to other users.
     *
     * @param array<string, mixed> $payload
     * @return array{data: array<string, mixed>}
     */
    public function split(array $payload): array
    {
        $keys = ['ppc_network_id', 'owner_user_id', 'copy_to_user_ids'];
        PayloadKeys::refuseUnknown($payload, $keys, 'a traffic source split', [
            'dry_run' => 'is not taken: GET /system/traffic-source-owners says what a split would do',
            'to_user_id' => 'is not taken: a split leaves the source in the account it is in. To have it in'
                . ' another, move it there first (POST /system/traffic-source-owners/repair with to_user_id),'
                . ' then split it',
            'all' => 'is not taken: each source is split by itself',
        ]);
        if (!array_key_exists('ppc_network_id', $payload)) {
            throw new ValidationException('ppc_network_id is required', [
                'ppc_network_id' => 'is required: the traffic source to split',
            ]);
        }
        $sourceId = QueryInt::required($payload, 'ppc_network_id', 1, self::MAX_ID, 'the traffic source to split');
        $shownOwner = array_key_exists('owner_user_id', $payload)
            ? QueryInt::required($payload, 'owner_user_id', 0, self::MAX_ID, 'the owner the report showed')
            : null;
        PayloadKeys::refuse(PayloadKeys::valueListErrors(
            $payload,
            'copy_to_user_ids',
            'user ids, the ones the report showed under named_by',
            static fn (mixed $id): ?string => (is_int($id) && $id >= 1 && $id <= self::MAX_ID)
                || (is_string($id) && preg_match('/\A[1-9][0-9]{0,7}\z/', $id) === 1 && (int) $id <= self::MAX_ID)
                ? null : 'must be a user id, a whole number from 1 to ' . self::MAX_ID
        ));
        $shownCopyTo = null;
        if (array_key_exists('copy_to_user_ids', $payload)) {
            $why = 'must be a list of the user ids the report showed under named_by';
            if ($payload['copy_to_user_ids'] === null) {
                throw new ValidationException('Invalid copy_to_user_ids', ['copy_to_user_ids' => $why]);
            }
            $shownCopyTo = array_map('intval', $payload['copy_to_user_ids']);
            if ($shownCopyTo === [] || count(array_unique($shownCopyTo)) !== count($shownCopyTo)) {
                throw new ValidationException('Invalid copy_to_user_ids', [
                    'copy_to_user_ids' => $why . ': at least one, each once',
                ]);
            }
        }

        $owners = $this->owners();
        $plan = $this->refused(fn (): array => $owners->planSplit($sourceId, $shownOwner, $shownCopyTo));
        $this->maySplit($plan['owner'], array_keys($plan['copies']));
        $actor = $this->auth->userId();
        $split = $this->refused(
            fn (): array => $owners->split($sourceId, $plan['owner'], $plan['copies'], $actor)
        );

        return ['data' => $split];
    }

    /** @return array{repaired: list<array<string, mixed>>, skipped: list<array<string, mixed>>} */
    private function repairAll(): array
    {
        $owners = $this->owners();
        $repaired = [];
        $skipped = [];
        // Ownership and ambiguity are all this reads; the counts scan clicks.
        foreach ($this->guard(fn (): array => $owners->report(false)) as $entry) {
            $sourceId = (int) $entry['ppc_network_id'];
            $skip = static fn (string $reason, string $message): array => [
                'ppc_network_id' => $sourceId,
                'ppc_network_name' => $entry['ppc_network_name'],
                'reason' => $reason,
                'ambiguous' => $entry['ambiguous'],
                'message' => $message,
            ];
            if ($entry['repair_to_user_id'] === null) {
                $users = implode(', ', array_column($entry['named_by'], 'user_id'));
                $skipped[] = $skip('ambiguous', 'It is moved only to a user you name: send {"ppc_network_id": '
                    . $sourceId . ', "to_user_id": …} with one of ' . $users . '.');
                continue;
            }
            $from = (int) $entry['owner']['user_id'];
            $to = (int) $entry['repair_to_user_id'];
            try {
                $this->mayMove($from, $to);
            } catch (AuthException $e) {
                $skipped[] = $skip('not_permitted', $e->getMessage());
                continue;
            }
            try {
                $repaired[] = $owners->repair($sourceId, $from, $to, null, $this->auth->userId());
            } catch (TrafficSourceRepairRefused $e) {
                $skipped[] = $skip('changed', $e->getMessage());
            } catch (\Throwable $e) {
                if ($repaired === []) {
                    throw new DatabaseException('traffic source repair: ' . $e->getMessage(), $e);
                }
                // The sources before it stand (CLAUDE.md #34).
                throw new WriteCommittedException('the traffic sources', $e, 'Traffic sources '
                    . implode(', ', array_column($repaired, 'ppc_network_id')) . ' were moved back, and moving'
                    . ' traffic source ' . $sourceId . ' failed, so nothing after it was tried. Sending the same'
                    . ' request again moves only what GET /system/traffic-source-owners still names a user for.');
            }
        }

        return ['repaired' => $repaired, 'skipped' => $skipped];
    }

    /**
     * The caller may act on both users' accounts, as Account › Users asks.
     * Account › Settings asks it of each source before offering the move.
     */
    public function mayMove(int $from, int $to): void
    {
        $this->auth->requireSelfOrMayManageUser($this->db, $from);
        $this->auth->requireSelfOrMayManageUser($this->db, $to);
    }

    /**
     * The caller may act on the user the source is in, whose source is
     * copied, and on each user who gets a copy, as mayMove() asks of a move.
     *
     * @param list<int> $copyTo
     */
    public function maySplit(int $owner, array $copyTo): void
    {
        foreach ([$owner, ...$copyTo] as $user) {
            $this->auth->requireSelfOrMayManageUser($this->db, $user);
        }
    }

    private function owners(): TrafficSourceOwners
    {
        return new TrafficSourceOwners(new Connection($this->db));
    }

    /**
     * A refusal is a 422 naming the field at fault; nothing was written.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function refused(callable $fn): mixed
    {
        try {
            return $this->guard($fn);
        } catch (TrafficSourceRepairRefused $e) {
            throw new ValidationException($e->getMessage(), [$e->field => $e->getMessage()], $e);
        }
    }

    /**
     * A database failure is a 500, never an answer: a report that could not
     * be read must not read as "nothing to repair".
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function guard(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Api\V3\HttpException | AuthException | TrafficSourceRepairRefused $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new DatabaseException('traffic source owners: ' . $e->getMessage(), $e);
        }
    }
}
