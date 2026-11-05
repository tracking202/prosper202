<?php

declare(strict_types=1);

namespace Prosper202\Conversion\PostbackAuth;

/**
 * What a postback or pixel request presents to authenticate itself, read
 * from its raw query string: `p202_token`, or `p202_ts` and `p202_sig`.
 *
 * The raw string, not $_GET: the signature covers every parameter as sent
 * (PostbackSignature), and PHP's parse renames some (`a.b` is `a_b`) and
 * drops repeats. Names are matched without case, as the click path reads
 * $_GET with its names lower-cased (connect2.php). An empty value is no
 * value, as an unfilled macro is elsewhere; a name sent twice is refused
 * rather than one of its values picked.
 */
final class PresentedCredentials
{
    public const TIMESTAMP = 'p202_ts';
    public const SIGNATURE = 'p202_sig';

    /**
     * @param list<array{0: string, 1: string}> $pairs every parameter but
     *        p202_sig, decoded, in the order sent
     */
    private function __construct(
        public readonly ?string $token,
        public readonly ?string $timestamp,
        public readonly ?string $signature,
        public readonly array $pairs,
        public readonly ?string $problem,
    ) {
    }

    public static function fromQueryString(string $query): self
    {
        $pairs = [];
        $found = [PostbackToken::PARAMETER => [], self::TIMESTAMP => [], self::SIGNATURE => []];
        foreach (explode('&', $query) as $piece) {
            if ($piece === '') {
                continue;
            }
            $eq = strpos($piece, '=');
            $name = urldecode($eq === false ? $piece : substr($piece, 0, $eq));
            $value = $eq === false ? '' : urldecode(substr($piece, $eq + 1));
            $key = strtolower($name);
            if (array_key_exists($key, $found)) {
                $found[$key][] = $value;
            }
            if ($key !== self::SIGNATURE) {
                $pairs[] = [$name, $value];
            }
        }

        $problem = null;
        $values = [];
        foreach ($found as $name => $sent) {
            if (count($sent) > 1) {
                $problem ??= $name . ' is sent ' . count($sent) . ' times; send it once.';
            }
            $value = $sent[0] ?? '';
            $values[$name] = $value === '' ? null : $value;
        }
        if ($problem === null && ($values[self::TIMESTAMP] === null) !== ($values[self::SIGNATURE] === null)) {
            $problem = 'A signed postback carries both p202_ts and p202_sig; this one carries only '
                . ($values[self::TIMESTAMP] === null ? 'p202_sig' : 'p202_ts') . '.';
        }

        return new self(
            $values[PostbackToken::PARAMETER],
            $values[self::TIMESTAMP],
            $values[self::SIGNATURE],
            $pairs,
            $problem,
        );
    }

    public function presentsNothing(): bool
    {
        return $this->token === null && $this->timestamp === null && $this->signature === null;
    }

    public function isSigned(): bool
    {
        return $this->timestamp !== null && $this->signature !== null;
    }
}
