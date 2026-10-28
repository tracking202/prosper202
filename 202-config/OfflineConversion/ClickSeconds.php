<?php

declare(strict_types=1);

namespace Prosper202\OfflineConversion;

/**
 * The second each conversion of a file is sent at. The platforms count one
 * conversion per click and second (Format::secondKey()), so two conversions
 * on one click in one second would lose one.
 *
 * The conversion recorded first (the lower conv_id) keeps its second, and
 * each other one moves to the next later second that no conversion on its
 * click holds, past a real conversion a second later too. Seconds are
 * handed out as the file is read, newest second first, so a conversion's
 * second depends only on the conversions on its click at its own second or
 * later: every one the read returns, sent or not.
 *
 * The platform keeps a conversion by its click and second, and Microsoft's
 * adjustments name it by them, so a sent conversion's second has to be the
 * same on every fetch, and is:
 *  - after a refund, a deletion or a replacement of another conversion on
 *    its click: the ledger keeps that one's row (a deletion is a flag), so
 *    it is still read and still holds its second, though no longer sent;
 *  - when the oldest conversions leave the window: they are handed out
 *    last;
 *  - when a conversion is recorded later at a second that holds
 *    conversions on its click: it goes after them.
 * The exception: a conversion recorded later at the second a moved one had
 * taken (just after several on its click in one second, with a fetch in
 * between, or recorded with a past time) takes that second, and the moved
 * one moves on. The platform then counts the moved one again at its new
 * second and ignores the later one as a duplicate of the moved one's old
 * second: the count stays right, and one value stands for the other.
 *
 * A moved second can be after the file was made, when conversions on one
 * click arrive in its last seconds; if the platform refuses it then, the
 * next fetch sends it at the same second, by then past.
 *
 * Only the clicks read in the last HORIZON seconds of conversion time are
 * held. A conversion read later reaches a second of one let go only through
 * more than HORIZON conversions on that one click in between.
 */
final class ClickSeconds
{
    public const HORIZON = 3600;

    /**
     * Each click's seconds taken, under the second of its latest conversion
     * read (the lowest it holds), the click read longest ago first.
     *
     * @var array<string, array{0: int, 1: array<int, true>}>
     */
    private array $taken = [];

    /** @var array{0: int, 1: int}|null the conversion time and conv_id of the last asked */
    private ?array $last = null;

    /**
     * The second a conversion on $click (Format::secondKey()) is sent at.
     * Asked in the read's order: newest second first, and within one second
     * the lowest conv_id first.
     */
    public function take(string $click, int $convTime, int $convId): int
    {
        if (
            $this->last !== null
            && ($convTime > $this->last[0] || ($convTime === $this->last[0] && $convId <= $this->last[1]))
        ) {
            throw new \LogicException(
                'conversion ' . $convId . ' at ' . $convTime . ' was asked after conversion ' . $this->last[1] . ' at '
                . $this->last[0] . ': seconds are handed out newest second first, lowest conv_id first within one'
            );
        }
        $this->last = [$convTime, $convId];
        foreach ($this->taken as $id => [$lowest]) {
            if ($lowest - $convTime <= self::HORIZON) {
                break;
            }
            unset($this->taken[$id]);
        }

        $seconds = $this->taken[$click][1] ?? [];
        unset($this->taken[$click]);
        $second = $convTime;
        while (isset($seconds[$second])) {
            $second++;
        }
        $seconds[$second] = true;
        $this->taken[$click] = [$convTime, $seconds];

        return $second;
    }
}
