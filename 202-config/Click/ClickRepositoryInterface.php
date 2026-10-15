<?php

declare(strict_types=1);

namespace Prosper202\Click;

interface ClickRepositoryInterface
{
    /**
     * A new click id from 202_clicks_counter, outside any transaction: every
     * path needs it before the write, for the public id and the URLs.
     */
    public function allocateClickId(): int;

    /**
     * A new click's rows, together or not at all.
     *
     * @param (\Closure(): int)|null $redirectSiteUrlId resolves the redirect
     *        URL from the rows just written and answers its site URL id, for
     *        202_clicks_site; null when the click carries the id itself
     */
    public function recordClick(ClickRecord $click, ?\Closure $redirectSiteUrlId = null): void;

    /**
     * A redirector re-click (rtr.php?lpr=) of a click already recorded: a
     * second 202_clicks row at the new time, the columns the redirector sets
     * rewritten and every other column kept, and the click marked for the
     * attribution rollup, together or not at all. The click must be one of
     * the record's account's (\LogicException otherwise).
     *
     * @param \Closure(): int $redirectSiteUrlId as for recordClick()
     */
    public function recordReClick(ClickRecord $click, \Closure $redirectSiteUrlId): void;

    /**
     * A recorded click leaving its landing page (ClickExit), together or not
     * at all, and only when the click, and the offer and rotator named with
     * it, are the exit's account's: false, with nothing written, when one is
     * not (a visitor names the click, and may name another account's).
     *
     * @param (\Closure(): int)|null $redirectSiteUrlId resolves the redirect
     *        URL from the rows just written (the offer's payout among them)
     *        and answers its site URL id, for 202_clicks_site with the exit's
     *        outbound URL; null when the exit records no URLs
     */
    public function recordExit(ClickExit $exit, ?\Closure $redirectSiteUrlId = null): bool;

    /**
     * Whether the click has rows and every one is the account's: for a script
     * to stop before work recordExit() would refuse to record (a rotation
     * turned, a URL looked up), not in place of that refusal.
     */
    public function isAccountsClick(int $userId, int $clickId): bool;
}
