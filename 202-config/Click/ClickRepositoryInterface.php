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
     * attribution rollup, together or not at all.
     *
     * @param \Closure(): int $redirectSiteUrlId as for recordClick()
     */
    public function recordReClick(ClickRecord $click, \Closure $redirectSiteUrlId): void;
}
