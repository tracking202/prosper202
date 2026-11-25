<?php

declare(strict_types=1);

namespace Prosper202\Click;

/**
 * The ad click ids (ClickRecord::AD_CLICK_IDS) a redirect passes on, less
 * those its destination already carries.
 *
 * getPrePopVars() passes every parameter of the link on but the tracker's own,
 * and the gbraid, wbraid and ttclid are not the tracker's: a landing page's
 * Google tag reads them from its URL. An offer URL that also names one with
 * its token (`gbraid=[[gbraid]]`) then received it twice, the token's value
 * and the link's. A click id the destination carries, filled or written in,
 * is carried once: the pass-on drops it. Every other parameter is passed on
 * as before, a duplicate included, since a reader that takes the last value
 * reads the link's.
 */
final class PassOnClickIds
{
    private function __construct()
    {
    }

    /**
     * The ad click ids $url's query carries, by name. The fragment is not
     * the query, and an id whose value is still an unfilled token
     * (`gbraid=[[gbraid]]` in an outage's cached URL) carries nothing: the
     * link's id goes on beside it, as before.
     *
     * @return array<string, true>
     */
    public static function carriedBy(string $url): array
    {
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $url = substr($url, 0, $hash);
        }
        $question = strpos($url, '?');
        if ($question === false) {
            return [];
        }
        $carried = [];
        foreach (explode('&', substr($url, $question + 1)) as $piece) {
            [$name, $value] = self::split($piece);
            if (isset(ClickRecord::AD_CLICK_IDS[$name]) && preg_match('/^\[\[[^\[\]]*\]\]$/', $value) !== 1) {
                $carried[$name] = true;
            }
        }

        return $carried;
    }

    /**
     * $passOn ('a=1&gbraid=x&', as getPrePopVars() writes it, or a cloaked
     * link's decoded 202vars) without the ad click ids $url carries, in the
     * same form: the pieces kept are kept byte for byte, in their order.
     */
    public static function withoutCarried(string $passOn, string $url): string
    {
        return self::without($passOn, self::carriedBy($url));
    }

    /**
     * $passOn without the pieces $names names, in the same form.
     *
     * @param array<string, true> $names
     */
    public static function without(string $passOn, array $names): string
    {
        if ($names === [] || $passOn === '') {
            return $passOn;
        }
        $kept = [];
        foreach (explode('&', $passOn) as $piece) {
            if (!isset($names[self::split($piece)[0]])) {
                $kept[] = $piece;
            }
        }
        $result = implode('&', $kept);

        // Only the separators were left: nothing to pass on.
        return trim($result, '&') === '' ? '' : $result;
    }

    /**
     * A query piece's name and value, decoded as PHP decodes them
     * (['gbraid', 'x'] of 'gbraid=x').
     *
     * @return array{string, string}
     */
    private static function split(string $piece): array
    {
        $equals = strpos($piece, '=');
        if ($equals === false) {
            return [urldecode($piece), ''];
        }

        return [urldecode(substr($piece, 0, $equals)), urldecode(substr($piece, $equals + 1))];
    }
}
