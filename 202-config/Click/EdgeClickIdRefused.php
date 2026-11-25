<?php

declare(strict_types=1);

namespace Prosper202\Click;

use RuntimeException;

/**
 * The install's click counter handed out an id in the edge's range
 * (ClickId::isEdge()). The click is not recorded: an id there would read as
 * the edge's, and could be one the edge has already given another click.
 */
final class EdgeClickIdRefused extends RuntimeException
{
    public function __construct(public readonly int $clickId)
    {
        parent::__construct(sprintf(
            'The click counter (202_clicks_counter) handed out click id %d, and ids from %d up are the hosted'
                . " edge's, so the click was not recorded. Something wrote an id that high into the counter, which"
                . ' moved its AUTO_INCREMENT; delete the counter\'s rows from %2$d up and set its AUTO_INCREMENT'
                . ' back to one above its highest remaining id.',
            $clickId,
            ClickId::EDGE_FIRST
        ));
    }
}
