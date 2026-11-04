<?php

declare(strict_types=1);

namespace Prosper202\Setup;

use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookRefused;
use Prosper202\Notifications\PostbackSender;

/**
 * "Check my page" on Setup › Get LP Code: fetches a landing page's saved URL
 * and says whether the page carries the code for that landing page.
 *
 * The URL is the user's, so the fetch goes out as a postback does: each hop
 * checked by WebhookGuard (public addresses only, the operator's allowlist
 * honoured), connected to the address it approved with PostbackSender's
 * pinned curl options, redirects followed by hand, the whole chain under
 * TIMEOUT and the body cut off at MAX_BYTES.
 *
 * It reads the page's HTML, so it finds the code as pasted: the current
 * loader (landing.php?lpip=N) or the paid 1.9.56 one (landing.js?lpip=N).
 * Code a tag manager adds after the page loads is not seen, and finding the
 * code does not prove a visit is recorded.
 *
 * @phpstan-type Answer array{status: int, error: string|null, timed_out: bool, primary_ip: string|null, redirect_url: string|null, body: string, truncated: bool}
 */
final class LandingPageCheck
{
    public const USER_AGENT = 'Mozilla/5.0 (compatible; Prosper202 landing page check)';
    /** Seconds for the whole fetch, redirects included. */
    public const TIMEOUT = 10;
    public const MAX_REDIRECTS = 5;
    /** Bytes of the page read; the rest is not fetched. */
    public const MAX_BYTES = 2097152;

    /** The loader's address in a page, either version: 1 its host (when written), 2 php or js, 3 the id. */
    private const SNIPPET = '~(?:(?:https?:)?(?:\\\\?/){2}([A-Za-z0-9.-]+(?::[0-9]{1,5})?)[^\s"\'<>]{0,300}?)?'
        . 'tracking202\\\\?/static\\\\?/landing\.(php|js)\?lpip=([0-9]*)~i';

    private ?WebhookGuard $guard;
    /** @var callable(array<int, mixed>): Answer */
    private $transport;

    /** @param (callable(array<int, mixed>): Answer)|null $transport */
    public function __construct(?WebhookGuard $guard = null, ?callable $transport = null)
    {
        $this->guard = $guard;
        $this->transport = $transport ?? [self::class, 'curlTransport'];
    }

    /**
     * @param string $landingPageUrl the landing page's saved URL, [[placeholders]] and all
     * @param string $publicId the landing page's public id, the lpip= its code carries
     * @param string $trackerHost this install's tracking host[:port], which its code loads from; '' when unknown
     */
    public function check(string $landingPageUrl, string $publicId, string $trackerHost = ''): LandingPageCheckResult
    {
        if (preg_match('/^[0-9]+$/D', $publicId) !== 1) {
            return self::error('This landing page has no public id, so no code was made for it to look for.');
        }
        // Placeholders are filled per click; the page is fetched without them.
        $url = preg_replace('/\[\[[^\]]*\]\]/', '', $landingPageUrl);
        if ($url === null) {
            return self::error('The landing page URL could not be read: ' . preg_last_error_msg() . '.');
        }
        $url = trim($url);
        if ($url === '') {
            return self::error('This landing page has no URL to check. Add it in Setup › Landing Pages.');
        }
        try {
            $this->guard ??= new WebhookGuard();
        } catch (\UnexpectedValueException $e) {
            return self::error('Your page was not fetched: ' . $e->getMessage());
        }

        $deadline = microtime(true) + self::TIMEOUT;
        $hop = $url;
        for ($redirects = 0;; $redirects++) {
            $where = PostbackSender::origin($hop);
            try {
                $target = $this->guard->checkPage($hop);
            } catch (WebhookRefused $e) {
                return self::error(($redirects === 0
                    ? 'Your page was not fetched: '
                    : 'Your page redirected to ' . $where . ', which was not followed: ') . $e->getMessage());
            }

            $left = (int) ceil($deadline - microtime(true));
            if ($left < 1) {
                return self::error('Checking your page took more than ' . self::TIMEOUT . ' seconds (looking up its host'
                    . ($redirects > 0 ? ' and following ' . $redirects . ' redirects' : '') . '), so it was stopped. Try again.');
            }
            $answer = ($this->transport)(self::curlOptions($target, $left));

            $status = $answer['status'];
            if ($status === 0) {
                return self::error($answer['timed_out']
                    ? 'Your page at ' . $where . ' did not answer within ' . self::TIMEOUT . ' seconds.'
                    : 'Your page at ' . $where . ' could not be fetched: ' . ($answer['error'] ?? 'no answer') . '.');
            }
            // An answer from an address that cannot be confirmed as the checked one is not read.
            $connected = $answer['primary_ip'] ?? '';
            if (!self::sameAddress($connected, $target->pinned())) {
                return self::error('The connection to ' . $where . ' went to ' . ($connected === '' ? 'an address curl did not report' : $connected)
                    . ' instead of the checked address ' . $target->pinned() . ', so the page was not read.');
            }
            if ($status >= 300 && $status < 400) {
                $location = $answer['redirect_url'];
                if ($location === null || $location === '') {
                    return self::error('Your page at ' . $where . ' answered HTTP ' . $status . ' without saying where to go.');
                }
                if ($redirects >= self::MAX_REDIRECTS) {
                    return self::error('Your page redirected more than ' . self::MAX_REDIRECTS . ' times, so it was not read.');
                }
                $hop = $location;
                continue;
            }
            if ($status < 200 || $status >= 300) {
                return self::error('Your page at ' . $where . ' answered HTTP ' . $status
                    . ', so its code could not be read. Check the URL saved for this landing page.');
            }

            return self::inspect($answer['body'], $publicId, $answer['truncated'], $where, $trackerHost);
        }
    }

    /**
     * What a page's HTML says about the code for $publicId. Pure, for tests.
     */
    public static function inspect(
        string $html,
        string $publicId,
        bool $truncated = false,
        string $where = '',
        string $trackerHost = ''
    ): LandingPageCheckResult {
        $yours = $where === '' ? 'Your page' : 'Your page at ' . $where;
        [$live, $comments] = self::splitComments($html);
        $found = self::snippets($live);
        $mine = array_values(array_filter($found, static fn (array $s): bool => $s[1] === $publicId));
        $others = array_values(array_unique(array_map(
            static fn (array $s): string => $s[1],
            array_filter($found, static fn (array $s): bool => $s[1] !== $publicId)
        )));
        $named = static fn (array $ids): string => implode(', ', array_map(
            static fn (string $id): string => $id === '' ? 'an lpip= with no id' : 'lpip=' . $id,
            $ids
        ));

        if ($mine !== []) {
            $tracker = strtolower($trackerHost);
            $here = array_values(array_filter($mine, static fn (array $s): bool => $tracker === '' || $s[2] === '' || $s[2] === $tracker));
            if ($here === []) {
                $hosts = array_values(array_unique(array_map(static fn (array $s): string => $s[2], $mine)));

                return new LandingPageCheckResult(LandingPageCheckResult::OTHER_TRACKER,
                    $yours . ' carries code for lpip=' . $publicId . ', but it loads from ' . implode(', ', $hosts)
                    . ', not from this install\'s tracking domain (' . $trackerHost . '). If that is another install, '
                    . 'the visits are recorded there: replace the code with the code above.');
            }
            $mine = $here;
            if ($others !== []) {
                return new LandingPageCheckResult(LandingPageCheckResult::MIXED,
                    $yours . ' carries this landing page\'s code (lpip=' . $publicId . ') and also code for '
                    . $named($others) . '. Only one of them records the visit: remove the other.', $others);
            }
            foreach ($mine as $s) {
                if ($s[0] === 'php') {
                    return new LandingPageCheckResult(LandingPageCheckResult::PRESENT,
                        'Found: ' . lcfirst($yours) . ' carries this landing page\'s code (lpip=' . $publicId . '). '
                        . 'This reads the page\'s HTML only: open the page through one of your tracking links and check the visit is recorded.');
                }
            }

            return new LandingPageCheckResult(LandingPageCheckResult::LEGACY,
                'Found: ' . lcfirst($yours) . ' carries the older landing.js code for this landing page (lpip=' . $publicId . '). '
                . 'It still works; replace it with the code above when you next edit the page.');
        }
        if ($others !== []) {
            return new LandingPageCheckResult(LandingPageCheckResult::WRONG_ID,
                $yours . ' carries code for another landing page (' . $named($others) . '), not this one (lpip='
                . $publicId . '). Its visits are recorded for that other landing page: replace the code with the code above.', $others);
        }
        foreach (self::snippets($comments) as $s) {
            if ($s[1] === $publicId) {
                return new LandingPageCheckResult(LandingPageCheckResult::COMMENTED,
                    $yours . ' has this landing page\'s code only inside an HTML comment (<!-- … -->), where it does not run.');
            }
        }

        return new LandingPageCheckResult(LandingPageCheckResult::ABSENT, ($truncated
            ? 'The code was not found in the first ' . intdiv(self::MAX_BYTES, 1048576) . ' MB of ' . lcfirst($yours) . ', which is larger than that. '
            : 'The code was not found on ' . lcfirst($yours) . '. ')
            . 'Paste the code above right above </body>. If a tag manager or another script adds it, this check cannot see it.');
    }

    /**
     * Each loader address in the HTML: [php|js, lpip digits, host as written lower-cased or ''].
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function snippets(string $html): array
    {
        if (preg_match_all(self::SNIPPET, $html, $m, PREG_SET_ORDER) === false) {
            throw new \RuntimeException('The page could not be searched: ' . preg_last_error_msg());
        }

        return array_map(static fn (array $one): array => [strtolower($one[2]), $one[3], strtolower($one[1])], $m);
    }

    /**
     * The HTML outside comments, and the comments' text. A `<!--` inside a
     * <script> element is script text, not a comment, as a browser reads it.
     *
     * @return array{0: string, 1: string}
     */
    private static function splitComments(string $html): array
    {
        $live = '';
        $comments = '';
        $at = 0;
        while (($open = strpos($html, '<!--', $at)) !== false) {
            $script = stripos($html, '<script', $at);
            if ($script !== false && $script < $open) {
                $closeScript = stripos($html, '</script', $script + 7);
                $end = $closeScript === false ? strlen($html) : $closeScript + 8;
                $live .= substr($html, $at, $end - $at);
                $at = $end;
                continue;
            }
            $live .= substr($html, $at, $open - $at) . ' ';
            $close = strpos($html, '-->', $open + 4);
            $end = $close === false ? strlen($html) : $close + 3;
            $comments .= substr($html, $open, $end - $open) . ' ';
            $at = $end;
        }

        return [$live . substr($html, $at), $comments];
    }

    /**
     * PostbackSender's pinned options for one hop, asking for a page.
     *
     * @return array<int, mixed>
     */
    public static function curlOptions(\Prosper202\Attribution\WebhookTarget $target, int $timeout): array
    {
        $options = PostbackSender::curlOptions($target, min(self::TIMEOUT, $timeout));
        $options[CURLOPT_USERAGENT] = self::USER_AGENT;
        $options[CURLOPT_HTTPHEADER] = ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.5'];
        $options[CURLOPT_ENCODING] = '';

        return $options;
    }

    /**
     * The real transfer, reading at most MAX_BYTES of the body.
     *
     * @param array<int, mixed> $options
     * @return Answer
     */
    public static function curlTransport(array $options): array
    {
        $none = ['status' => 0, 'error' => null, 'timed_out' => false, 'primary_ip' => null, 'redirect_url' => null, 'body' => '', 'truncated' => false];
        $ch = curl_init();
        if ($ch === false) {
            return ['error' => 'curl could not be initialised'] + $none;
        }
        $body = '';
        $truncated = false;
        $options[CURLOPT_RETURNTRANSFER] = false;
        $options[CURLOPT_WRITEFUNCTION] = static function ($handle, string $chunk) use (&$body, &$truncated): int {
            $room = self::MAX_BYTES - strlen($body);
            if (strlen($chunk) > $room) {
                $body .= substr($chunk, 0, max(0, $room));
                $truncated = true;

                return 0;
            }
            $body .= $chunk;

            return strlen($chunk);
        };
        if (!curl_setopt_array($ch, $options)) {
            $error = curl_error($ch);

            return ['error' => 'curl refused an option: ' . $error] + $none;
        }
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $primary = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $redirect = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $error = $ok === false && !$truncated ? curl_error($ch) : null;

        return [
            'status' => $error === null ? $status : 0,
            'error' => $error,
            'timed_out' => $errno === CURLE_OPERATION_TIMEDOUT,
            'primary_ip' => is_string($primary) && $primary !== '' ? $primary : null,
            'redirect_url' => is_string($redirect) && $redirect !== '' ? $redirect : null,
            'body' => $body,
            'truncated' => $truncated,
        ];
    }

    private static function error(string $message): LandingPageCheckResult
    {
        return new LandingPageCheckResult(LandingPageCheckResult::ERROR, $message);
    }

    private static function sameAddress(string $a, string $b): bool
    {
        $x = @inet_pton($a);
        $y = @inet_pton($b);

        return $x !== false && $y !== false && $x === $y;
    }
}
