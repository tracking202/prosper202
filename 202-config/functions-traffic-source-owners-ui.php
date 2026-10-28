<?php

/**
 * Account › Settings' notice and panel for the traffic sources another
 * user's traffic source accounts name: what Setup › Traffic Sources' rename
 * could leave before 1.9.79 (Prosper202\Setup\TrafficSourceOwners says how,
 * and what else the same rows can mean).
 *
 * Nothing here decides who may see or move a source, or how one moves: the
 * page runs TrafficSourceOwnersController, the code GET
 * /system/traffic-source-owners and POST /system/traffic-source-owners/repair
 * run, for an Api\V3\Auth of the signed-in user, after the two checks the
 * routes' group runs (requireAdmin(), then access_to_settings). The repair's
 * locks, its re-read and its refusals are the controller's and the domain
 * class's; this file turns a form into the API's body and the answer into a
 * sentence.
 */

declare(strict_types=1);

// Aliased: class names are case-blind, and a bare `Auth` import makes the
// legacy session guard's AUTH:: resolve to it.
use Api\V3\Auth as ApiAuth;
use Api\V3\AuthException;
use Api\V3\Controllers\TrafficSourceOwnersController;
use Api\V3\Exception\ValidationException;

/** The panel's id, and the fragment a repair lands on. */
const P202_TS_OWNERS_PANEL = 'traffic-source-owners';

/** The query that opens the panel: its counts scan clicks, so it is not read on every load. */
const P202_TS_OWNERS_REVIEW = 'traffic_sources=review';

/** Why a source moves only to a user the operator names (TrafficSourceOwners' marks). */
const P202_TS_OWNERS_REASONS = [
    'owner_has_accounts' => 'the user it is in has traffic source accounts on it too',
    'several_users' => 'traffic source accounts of more than one other user name it',
    'saved_after_rename' => 'an account was saved after the source was, as an account put on a source'
        . ' shared on purpose before 1.9.77 was',
    'no_such_user' => 'a user it names no longer exists',
    'deleted_user' => 'a user it names is deleted, and nothing moves into a deleted account',
];

/**
 * The controller, for the signed-in user, behind the routes' own checks.
 *
 * @throws AuthException 401 or 403 when the user may not, 500 when that could not be read
 */
function p202_ts_owners_controller(\mysqli $db, int $userId): TrafficSourceOwnersController
{
    $auth = ApiAuth::forSignedInUser($userId, $db);
    $auth->requireAdmin();
    $auth->requirePermission($db, 'access_to_settings');

    return new TrafficSourceOwnersController($db, $auth);
}

/**
 * What the page shows: nothing to a user the routes refuse; the report, with
 * counts only when the panel is open; or a sentence saying it could not be
 * read, never an empty report in its place (CLAUDE.md #11).
 *
 * @return array{controller: ?TrafficSourceOwnersController, report: ?array<string, mixed>, error: ?string}
 */
function p202_ts_owners_read(\mysqli $db, int $userId, bool $counts): array
{
    $unread = 'Whether a traffic source is in another user\'s account could not be read just now;'
        . ' the server log says why. Reload the page to try again.';
    try {
        $controller = p202_ts_owners_controller($db, $userId);
    } catch (AuthException $e) {
        if ($e->getCode() < 500) {
            return ['controller' => null, 'report' => null, 'error' => null];
        }
        error_log('Account › Settings: whether user ' . $userId . ' may read the traffic source owners could not'
            . ' be checked: ' . $e->getMessage());

        return ['controller' => null, 'report' => null, 'error' => $unread];
    }
    try {
        return ['controller' => $controller, 'report' => $controller->report($counts)['data'], 'error' => null];
    } catch (\Throwable $e) {
        error_log('Account › Settings: the traffic source owners could not be read: ' . $e->getMessage()
            . ($e->getPrevious() !== null ? ' (' . $e->getPrevious()->getMessage() . ')' : ''));

        return ['controller' => $controller, 'report' => null, 'error' => $unread];
    }
}

/**
 * A repair posted from the panel: the form's fields, named as the API names
 * them, are the body POST /system/traffic-source-owners/repair takes, and
 * the controller's repair() moves the source or refuses. Its answer is said
 * in the panel the redirect lands on. The session token is asked here as
 * well as by the page, so no caller moves a source without it.
 *
 * @param array<string, mixed> $post
 */
function p202_ts_owners_repair_posted(\mysqli $db, int $userId, array $post): never
{
    $back = '202-account/administration.php?' . P202_TS_OWNERS_REVIEW . '#' . P202_TS_OWNERS_PANEL;
    if (!AUTH::csrf_token_matches($post['token'] ?? null)) {
        p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
        p202_account_redirect($back);
    }
    $source = $post['traffic_source_repair'] ?? null;
    $payload = ['ppc_network_id' => $source];
    foreach (['owner_user_id', 'repair_to_user_id', 'to_user_id'] as $field) {
        if (array_key_exists($field, $post)) {
            $payload[$field] = $post[$field];
        }
    }
    $which = is_string($source) && ctype_digit($source) ? 'traffic source ' . $source : 'the traffic source';
    try {
        $moved = p202_ts_owners_controller($db, $userId)->repair($payload)['data']['repaired'][0];
        p202_account_flash('ok', sprintf(
            'Traffic source "%s" (%d) moved from user %d\'s account to user %d\'s, whose %d traffic source'
            . ' account(s) name it. Its clicks are reported under it again after the cron job\'s next run.',
            $moved['ppc_network_name'],
            $moved['ppc_network_id'],
            $moved['from_user_id'],
            $moved['to_user_id'],
            $moved['ppc_accounts']
        ));
    } catch (ValidationException $e) {
        p202_account_flash('bad', p202_ts_owners_refusal($e));
    } catch (AuthException $e) {
        if ($e->getCode() >= 500) {
            error_log('Account › Settings: whether user ' . $userId . ' may move ' . $which . ' could not be'
                . ' checked: ' . $e->getMessage());
            p202_account_flash('bad', 'Whether you may move ' . $which . ' could not be checked just now,'
                . ' so nothing was moved. Try again.');
        } else {
            p202_account_flash('bad', $e->getMessage() . ' Nothing was moved.');
        }
    } catch (\Throwable $e) {
        error_log('Account › Settings: moving ' . $which . ' failed: ' . $e->getMessage()
            . ($e->getPrevious() !== null ? ' (' . $e->getPrevious()->getMessage() . ')' : ''));
        p202_account_flash('bad', 'Moving ' . $which . ' failed, and the server log says why.'
            . ' If it is still listed below, it was not moved.');
    }
    p202_account_redirect($back);
}

/** A 422's sentences: the refusal's own, or each field's when it names them. */
function p202_ts_owners_refusal(ValidationException $e): string
{
    $said = [];
    foreach ($e->getFieldErrors() as $field => $text) {
        $said[] = $text === $e->getMessage() ? $text : $field . ': ' . $text;
    }
    $said = array_values(array_unique($said));

    return $said === [] ? $e->getMessage() : implode(' ', $said);
}

/**
 * The notice above the tiles: how many sources are listed, how many move as
 * they are, and the link that opens the panel.
 *
 * @param array<string, mixed> $report the controller's report data
 */
function p202_ts_owners_notice(array $report, string $self): string
{
    $h = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $listed = count($report['sources']);
    $repairable = (int) $report['repairable'];
    $ambiguous = (int) $report['ambiguous'];
    $what = $listed === 1
        ? '1 traffic source is in one user\'s account while another user\'s traffic source accounts name it.'
        : $listed . ' traffic sources are in one user\'s account while another user\'s traffic source accounts'
            . ' name them.';
    $split = [];
    if ($repairable > 0) {
        $split[] = $repairable . ' can be moved back as ' . ($repairable === 1 ? 'it is' : 'they are');
    }
    if ($ambiguous > 0) {
        $split[] = $ambiguous . ' ' . ($ambiguous === 1 ? 'needs' : 'need') . ' you to choose the user';
    }

    return '<div class="alert alert-warning p202-flash" role="status" id="traffic-source-owners-notice">'
        . '<i class="bi bi-exclamation-triangle"></i><div class="p202-flash__body">'
        . '<strong>' . $h($what) . '</strong>'
        . ' Before 1.9.79, renaming a traffic source in Setup could move it into the account of whoever saved'
        . ' the rename, and the reports of the user whose accounts name it lost it. '
        . $h(ucfirst(implode('; ', $split))) . '.'
        . ' <a href="' . $h($self . '?' . P202_TS_OWNERS_REVIEW . '#' . P202_TS_OWNERS_PANEL) . '">'
        . ($listed === 1 ? 'Review it' : 'Review them') . '</a>'
        . '</div></div>';
}

/**
 * The open panel, and the confirmations its moves open (rendered at the end
 * of the page, outside the table's scrolling frame).
 *
 * @param array<string, mixed> $report the controller's report data, with counts
 * @return array{panel: string, modals: string}
 */
function p202_ts_owners_panel(array $report, TrafficSourceOwnersController $controller, string $self): array
{
    $h = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $sources = $report['sources'];
    $action = $self . '?' . P202_TS_OWNERS_REVIEW . '#' . P202_TS_OWNERS_PANEL;

    $panel = '<section class="p202-panel" id="' . P202_TS_OWNERS_PANEL . '">'
        . '<div class="p202-panel__head"><h2 class="p202-panel__title">Traffic sources in another user\'s account</h2>'
        . ($sources === [] ? '' : '<span class="p202-pill p202-pill--warn">' . count($sources) . ' to review</span>')
        . '</div><div class="p202-panel__body">';
    $modals = '';

    if ($sources === []) {
        $panel .= '<div class="p202-empty">'
            . '<i class="bi bi-check2-circle p202-empty__icon"></i>'
            . '<strong class="p202-empty__title">No traffic source is in another user\'s account</strong>'
            . '<div>Every traffic source account names a source of its own user\'s.</div>'
            . '</div>';
    } else {
        // The move sits under "Goes to", not in a column of its own: a
        // visually-hidden header has no positioned ancestor inside the
        // scrolling frame, so it sits at the table's right edge and widens
        // the page on a phone (measured: 599px at 390).
        $panel .= '<p class="text-secondary">Each source below is in one user\'s account while traffic source'
            . ' accounts of another user name it. Moving one back changes only whose account it is in.</p>'
            . '<div class="p202-table-wrap"><table class="table p202-table" id="traffic-source-owners-table">'
            . '<thead><tr><th>Traffic source</th><th>In the account of</th><th>Named by</th><th>Goes to</th>'
            . '</tr></thead><tbody>';
        foreach ($sources as $entry) {
            [$cell, $modal] = p202_ts_owners_action($entry, $controller, $action);
            $panel .= '<tr data-ppc-network-id="' . (int) $entry['ppc_network_id'] . '">'
                . '<td><strong>' . $h($entry['ppc_network_name']) . '</strong> <span class="text-secondary">#'
                . (int) $entry['ppc_network_id'] . '</span>'
                . ($entry['removed'] ? ' <span class="p202-pill">removed</span>' : '') . '</td>'
                . '<td>' . p202_ts_owners_user($entry['owner'])
                . ($entry['owner']['ppc_account_ids'] === [] ? '' : '<div class="text-secondary">and their own '
                    . p202_ts_owners_holdings($entry['owner']) . '</div>') . '</td>'
                . '<td>' . implode('', array_map(
                    static fn (array $party): string => '<div>' . p202_ts_owners_user($party)
                        . '<div class="text-secondary">' . p202_ts_owners_holdings($party) . '</div></div>',
                    $entry['named_by']
                )) . '</td>'
                . '<td>' . p202_ts_owners_goes_to($entry) . '<div class="mt-2">' . $cell . '</div></td>'
                . '</tr>';
            $modals .= $modal;
        }
        $panel .= '</tbody></table></div>';
    }

    $panel .= '<details class="p202-disclosure mt-3" data-p202-remember="settings-traffic-source-owners">'
        . '<summary>Advanced <span class="p202-disclosure__hint">why a source is listed, and the API</span></summary>'
        . '<div class="p202-disclosure__body">'
        . '<p>Before 1.9.79, renaming a traffic source on Setup › Traffic Sources moved it into the account of the'
        . ' user who saved the rename, and nothing checked whose source it was. Its traffic source accounts stayed'
        . ' with their user, whose reports have put each click on them under no traffic source since.</p>'
        . '<p>The same rows have other origins: before 1.9.77 the API put a traffic source account under whatever'
        . ' source it was sent, and an admin may have moved a source on purpose. So nothing moves by itself: a'
        . ' source that may have been shared on purpose moves only to a user you choose. A move changes only'
        . ' whose account the source is in. Its name stays as it is, a removed source stays removed, and its'
        . ' accounts\' clicks are reported under it again after the cron job\'s next run. A source taken with no'
        . ' traffic source account under it leaves no sign and is not listed.</p>'
        . '<p class="mb-0">The same list and move are <code>GET /api/v3/system/traffic-source-owners</code> and'
        . ' <code>POST /api/v3/system/traffic-source-owners/repair</code>, and'
        . ' <code>p202 system traffic-source-owners show</code> and <code>repair</code> in the CLI.</p>'
        . '</div></details>';
    $panel .= '</div></section>';

    return ['panel' => $panel, 'modals' => $modals];
}

/**
 * A user as the report names one: their name and id, and whether they are
 * deleted, switched off or missing.
 *
 * @param array<string, mixed> $party
 */
function p202_ts_owners_user(array $party): string
{
    $h = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $id = (int) $party['user_id'];
    if ($party['user_name'] === null) {
        return 'user ' . $id . ' <span class="p202-pill p202-pill--bad">no such user</span>';
    }

    return $h($party['user_name']) . ' <span class="text-secondary">(user ' . $id . ')</span>'
        . ($party['user_deleted'] === true ? ' <span class="p202-pill p202-pill--bad">deleted</span>' : '')
        . ($party['user_deleted'] !== true && $party['user_active'] === false
            ? ' <span class="p202-pill p202-pill--warn">switched off</span>' : '');
}

/**
 * A user's traffic source accounts on the source, with the clicks and
 * conversions recorded on them when the report counted them.
 *
 * @param array<string, mixed> $party
 */
function p202_ts_owners_holdings(array $party): string
{
    $parts = [p202_ts_owners_accounts($party)
        . ' (' . implode(', ', array_map('intval', $party['ppc_account_ids'])) . ')'];
    if (isset($party['clicks'], $party['conversions'])) {
        $parts[] = p202_ts_owners_traffic($party);
    }

    return implode(' · ', $parts);
}

/** @param array<string, mixed> $party "2 traffic source accounts and 1 removed" */
function p202_ts_owners_accounts(array $party): string
{
    $live = (int) $party['ppc_accounts'];
    $removed = (int) $party['removed_ppc_accounts'];

    return $live . ' traffic source account' . ($live === 1 ? '' : 's')
        . ($removed > 0 ? ' and ' . $removed . ' removed' : '');
}

/** @param array<string, mixed> $party "3 clicks · 1 conversion", from a counted report */
function p202_ts_owners_traffic(array $party): string
{
    $clicks = (int) $party['clicks'];
    $conversions = (int) $party['conversions'];

    return number_format($clicks) . ' click' . ($clicks === 1 ? '' : 's') . ' · '
        . number_format($conversions) . ' conversion' . ($conversions === 1 ? '' : 's');
}

/**
 * Where a repair takes the source: the user the report names, or the
 * reasons it moves only to a user the operator chooses.
 *
 * @param array<string, mixed> $entry
 */
function p202_ts_owners_goes_to(array $entry): string
{
    $h = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    if ($entry['repair_to_user_id'] !== null) {
        foreach ($entry['named_by'] as $party) {
            if ((int) $party['user_id'] === (int) $entry['repair_to_user_id']) {
                return p202_ts_owners_user($party);
            }
        }
    }
    $reasons = array_map(
        static fn (string $reason): string => '<li>' . $h(P202_TS_OWNERS_REASONS[$reason] ?? $reason) . '</li>',
        $entry['ambiguous']
    );

    return '<span class="p202-pill p202-pill--warn">you choose</span>'
        . '<ul class="mb-0 mt-1 ps-3 text-secondary">' . implode('', $reasons) . '</ul>';
}

/**
 * A row's move: the button that opens its confirmation, and the
 * confirmation, which names the source, the user it leaves and the user it
 * goes to, or asks which. A user the viewer may not act on, as the repair
 * would refuse (TrafficSourceOwnersController::mayMove()), is not offered,
 * and the cell says why when no one is.
 *
 * @param array<string, mixed> $entry
 * @return array{0: string, 1: string} the cell, the modal
 */
function p202_ts_owners_action(array $entry, TrafficSourceOwnersController $controller, string $action): array
{
    $h = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $id = (int) $entry['ppc_network_id'];
    $from = (int) $entry['owner']['user_id'];
    $name = (string) $entry['ppc_network_name'];
    $modalId = 'ts-repair-' . $id;
    $refused = static function (int $to) use ($controller, $from): ?string {
        try {
            $controller->mayMove($from, $to);
        } catch (AuthException $e) {
            if ($e->getCode() >= 500) {
                throw $e;
            }
            return $e->getMessage();
        }
        return null;
    };

    $fields = p202_account_token_field()
        . '<input type="hidden" name="traffic_source_repair" value="' . $id . '">'
        . '<input type="hidden" name="owner_user_id" value="' . $from . '">';

    if ($entry['repair_to_user_id'] !== null) {
        $to = (int) $entry['repair_to_user_id'];
        $why = $refused($to);
        if ($why !== null) {
            return ['<span class="text-secondary">' . $h($why) . '</span>', ''];
        }
        $target = null;
        foreach ($entry['named_by'] as $party) {
            if ((int) $party['user_id'] === $to) {
                $target = $party;
            }
        }
        $target = (array) $target;
        $body = '<input type="hidden" name="repair_to_user_id" value="' . $to . '">'
            . '<p>&ldquo;' . $h($name) . '&rdquo; moves from ' . p202_ts_owners_user($entry['owner']) . ' to '
            . p202_ts_owners_user($target) . ', whose ' . p202_ts_owners_accounts($target) . ' name it ('
            . p202_ts_owners_traffic($target) . ').</p>';
        $title = 'Move “' . $name . '” back?';
        $submit = 'Move it back';
    } else {
        $choices = '';
        $offered = 0;
        foreach ($entry['named_by'] as $party) {
            $to = (int) $party['user_id'];
            $why = $party['user_name'] === null ? 'no such user'
                : ($party['user_deleted'] === true ? 'deleted' : $refused($to));
            $input = $modalId . '-to-' . $to;
            $choices .= '<div class="form-check"><input class="form-check-input" type="radio" name="to_user_id"'
                . ' id="' . $input . '" value="' . $to . '"' . ($why === null ? ' required' : ' disabled') . '>'
                . '<label class="form-check-label" for="' . $input . '">' . p202_ts_owners_user($party)
                . ' <span class="text-secondary">' . p202_ts_owners_holdings($party)
                . ($why === null ? '' : ' · not offered: ' . $h($why)) . '</span></label></div>';
            $offered += $why === null ? 1 : 0;
        }
        if ($offered === 0) {
            return ['<span class="text-secondary">No user you may act on can take it.</span>', ''];
        }
        $body = '<p>&ldquo;' . $h($name) . '&rdquo; is in the account of ' . p202_ts_owners_user($entry['owner'])
            . '. It goes only to a user whose traffic source accounts name it, and only to the one you choose.</p>'
            . '<div class="mb-3" role="radiogroup" aria-labelledby="' . $modalId . '-to">'
            . '<div class="form-label" id="' . $modalId . '-to">Move it to</div>' . $choices . '</div>';
        $title = 'Move “' . $name . '” to which user?';
        $submit = 'Move it';
    }

    $body .= '<p class="mb-0">Only whose account it is changes. Its name stays as it is, its traffic source'
        . ' accounts stay where they are, and their clicks are reported under it again after the cron job\'s next'
        . ' run. If it has changed since this page was read, nothing is moved and the page says so.</p>';
    $modal = '<div class="modal fade" id="' . $modalId . '" tabindex="-1" aria-labelledby="' . $modalId . '-title"'
        . ' aria-hidden="true"><div class="modal-dialog"><div class="modal-content">'
        . '<form method="post" action="' . $h($action) . '">' . $fields
        . '<div class="modal-header"><h5 class="modal-title" id="' . $modalId . '-title">' . $h($title) . '</h5>'
        . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        . '<div class="modal-body">' . $body . '</div>'
        . '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">'
        . 'Keep it where it is</button><button type="submit" class="btn btn-primary">' . $submit . '</button></div>'
        . '</form></div></div></div>';
    $cell = '<button type="button" class="btn btn-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#'
        . $modalId . '">Move back…</button>';

    return [$cell, $modal];
}
