<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

use InvalidArgumentException;

/**
 * Where a provider's notification carries the click, in that provider's
 * words: the field the click comes back in, and the variable on the link
 * the buyer follows that a tracker fills with the click id. The processor
 * words its answers for a notification that names no click, or a click the
 * account does not have, from it, so each seller reads their own platform's
 * names.
 */
final class ClickField
{
    /** The token a tracker fills with the click id. */
    public const TOKEN = '[[subid]]';

    /**
     * @param string $field the field, as the notification names it ("custom_subid")
     * @param string $link the link the buyer follows ("the checkout link")
     * @param string $variable the variable on that link a tracker fills ("subid=[[subid]]")
     */
    public function __construct(
        public readonly string $field,
        public readonly string $link,
        public readonly string $variable,
    ) {
        foreach (['field' => $field, 'link' => $link, 'variable' => $variable] as $name => $part) {
            if (trim($part) !== $part || $part === '' || preg_match('/[\x00-\x1F\x7F]/', $part) === 1) {
                throw new InvalidArgumentException('a click field\'s ' . $name . ' is text without space around it');
            }
        }
        if (!str_contains($variable, self::TOKEN)) {
            throw new InvalidArgumentException(
                'a click field\'s variable names the ' . self::TOKEN . ' a tracker fills'
            );
        }
    }
}
