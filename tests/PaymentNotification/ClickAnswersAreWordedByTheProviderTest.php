<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use PHPUnit\Framework\TestCase;
use Prosper202\PaymentNotification\ClickBank\ClickBankAdapter;
use Prosper202\PaymentNotification\ClickField;
use Prosper202\PaymentNotification\InboundRequest;
use Prosper202\PaymentNotification\JVZoo\JVZooAdapter;
use Prosper202\PaymentNotification\JVZoo\JVZooRole;
use Prosper202\PaymentNotification\Notification;
use Prosper202\PaymentNotification\NotificationKind;
use Prosper202\PaymentNotification\Outcome;
use Prosper202\PaymentNotification\OutcomeKind;
use Prosper202\PaymentNotification\PayKickstart\PayKickstartAdapter;
use Prosper202\PaymentNotification\Processor;
use Prosper202\PaymentNotification\ProviderAdapter;
use Prosper202\PaymentNotification\Response;
use Prosper202\PaymentNotification\Verification;
use Tests\Support\SourceScan;

/**
 * The answers about the click (a notification that names none, a click the
 * account does not have) are the Processor's, worded from the adapter's
 * ClickField, so each provider's seller reads their own platform's names.
 * They were PayKickstart's words in the Processor, and JVZoo and ClickBank
 * raised NO_CLICK themselves to get around them: three copies of one rule.
 *
 * Every ProviderAdapter in the tree is listed here with each way it is
 * built, so a new one is held to the same answers; the shared files' string
 * literals name no provider's field; and no adapter, nor any file beside
 * the shared ones, raises NO_CLICK or UNKNOWN_CLICK but the one listed,
 * which carries what the shared answer cannot.
 */
final class ClickAnswersAreWordedByTheProviderTest extends TestCase
{
    private const SHARED = '202-config/PaymentNotification';

    /** Shared files whose literals may name a provider's field => why. */
    private const SHARED_EXEMPT = [
        'ClickField.php' => 'defines the [[subid]] token a click field\'s variable names',
    ];

    /** Shared files that name the click outcomes => why. */
    private const OUTCOME_HOMES = [
        '202-config/PaymentNotification/OutcomeKind.php' => 'declares them',
        '202-config/PaymentNotification/Processor.php' => 'answers them, from the adapter\'s ClickField',
        '202-config/PaymentNotification/Response.php' => 'maps them to their status',
    ];

    /** Other files that raise a click outcome themselves: file => outcome => why (one site each). */
    private const RAISES_ITS_OWN = [
        '202-config/PaymentNotification/JVZoo/JVZooAdapter.php' => [
            'NO_CLICK' => 'an affiliate\'s tid that is there and is not a click id: the tid is the affiliate\'s to'
                . ' use for any tracker, so it names no click rather than being refused, and the answer shows the'
                . ' value, which a Notification does not carry',
        ],
    ];

    /** Words of a click field that are English, not a provider's names. */
    private const PLAIN_WORDS = ['in', 'or', 'no', 'the', 'tracking', 'code'];

    /** The tokens a class name is written in. */
    private const NAMES = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

    /** The tokens a string's text is written in. */
    private const STRINGS = [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE];

    /** @return array<string, ProviderAdapter> every adapter in the tree, each way it is built */
    public static function adapters(): array
    {
        return [
            'PayKickstart' => new PayKickstartAdapter(),
            'JVZoo affiliate' => new JVZooAdapter(JVZooRole::AFFILIATE),
            'JVZoo vendor' => new JVZooAdapter(JVZooRole::VENDOR),
            'ClickBank' => new ClickBankAdapter(),
        ];
    }

    /** The real Processor's answer; one about a notification that names no click reads no database. */
    public static function answer(ProviderAdapter $adapter, Notification $notification): Outcome
    {
        $request = new InboundRequest('POST', 'application/x-www-form-urlencoded', [], '203.0.113.9');
        $db = mysqli_init();
        self::assertInstanceOf(\mysqli::class, $db);

        return (new Processor($db))->process($notification, new Verification([1 => 0]), $adapter, $request);
    }

    /**
     * What names a provider's click in its sellers' words: the field and the
     * link whole, and each word of the field and the variable that is not
     * plain English ("custom_subid", "subid", "tid").
     *
     * @return list<string>
     */
    public static function names(ClickField $click): array
    {
        $words = preg_split('/[^A-Za-z0-9_]+/', $click->field . ' ' . $click->variable, -1, PREG_SPLIT_NO_EMPTY);
        self::assertIsArray($words);
        $names = [$click->field, $click->link];
        foreach ($words as $word) {
            if (!in_array(strtolower($word), self::PLAIN_WORDS, true)) {
                $names[] = $word;
            }
        }

        return array_values(array_unique($names));
    }

    /** Whether $text names $name: a whole word or phrase, any case. */
    public static function namesIn(string $text, string $name): bool
    {
        return preg_match('/(?<![A-Za-z0-9_])' . preg_quote($name, '/') . '(?![A-Za-z0-9_])/i', $text) === 1;
    }

    /**
     * Asserts that an answer names this adapter's click field, and none of
     * another adapter's names that this one does not share.
     */
    public static function assertNamesOnlyItsOwnField(ProviderAdapter $adapter, string $message): void
    {
        $own = $adapter->clickField();
        foreach ([$own->field, $own->link, $own->variable] as $part) {
            self::assertStringContainsString($part, $message, 'the answer names ' . $adapter->name() . '\'s click');
        }
        $ownNames = array_map('strtolower', self::names($own));
        foreach (self::adapters() as $label => $other) {
            foreach (self::names($other->clickField()) as $name) {
                if (!in_array(strtolower($name), $ownNames, true)) {
                    self::assertFalse(
                        self::namesIn($message, $name),
                        $adapter->name() . '\'s answer names "' . $name . '", which is ' . $label . '\'s: ' . $message
                    );
                }
            }
        }
    }

    public function testEveryProviderAdapterInTheTreeIsListedHere(): void
    {
        $listed = array_values(array_unique(array_map(
            static fn (ProviderAdapter $adapter): string => $adapter::class,
            self::adapters()
        )));
        sort($listed);
        $found = array_keys(self::implementations());
        sort($found);

        self::assertSame($listed, $found, 'a ProviderAdapter this test does not build: add it to adapters(), each way'
            . ' it is built, so its answers about the click are held to its own ClickField');
    }

    public function testANotificationNamingNoClickIsAnsweredInItsProvidersNames(): void
    {
        foreach (self::adapters() as $label => $adapter) {
            $notifications = [
                'sale' => new Notification(NotificationKind::SALE, 'SALE', 'TX-0001', null, '47.00'),
                'rebill' => new Notification(NotificationKind::REBILL, 'BILL', 'TX-0002', null, '9.95'),
                'refund' => new Notification(NotificationKind::REFUND, 'RFND', 'TX-0001', 'TX-0001'),
                'chargeback' => new Notification(NotificationKind::CHARGEBACK, 'CGBK', 'TX-0001', 'TX-0001'),
            ];
            foreach ($notifications as $what => $notification) {
                $outcome = self::answer($adapter, $notification);

                $case = $label . ' ' . $what . ': ' . $outcome->message;
                self::assertSame(OutcomeKind::NO_CLICK, $outcome->kind, $case);
                self::assertSame(200, Response::status($outcome->kind));
                self::assertStringStartsWith(
                    'Verified. The ' . $notification->event . ' notification of transaction TX-000',
                    $outcome->message
                );
                self::assertStringContainsString(
                    $notification->kind->records() ? 'nothing was recorded' : 'there is no sale to reverse',
                    $outcome->message
                );
                self::assertNamesOnlyItsOwnField($adapter, $outcome->message);
            }
        }
    }

    public function testAClickFieldIsPlainTextAndItsVariableNamesTheToken(): void
    {
        $refused = [
            ['', 'the checkout link', 'subid=[[subid]]'],
            [' custom_subid', 'the checkout link', 'subid=[[subid]]'],
            ['custom_subid', "the checkout\nlink", 'subid=[[subid]]'],
            ['custom_subid', 'the checkout link', 'subid='],
            ['custom_subid', 'the checkout link', 'subid=[[SUBID]]'],
        ];
        foreach ($refused as [$field, $link, $variable]) {
            try {
                new ClickField($field, $link, $variable);
                self::fail('accepted ' . json_encode([$field, $link, $variable]));
            } catch (\InvalidArgumentException $e) {
                self::assertStringStartsWith('a click field\'s ', $e->getMessage());
            }
        }
        $kept = new ClickField('custom_subid', 'the checkout link', 'subid=[[subid]]');
        self::assertSame(['custom_subid', 'subid=[[subid]]'], [$kept->field, $kept->variable]);
    }

    public function testEachAdapterNamesAFieldOfItsOwn(): void
    {
        $fields = [];
        foreach (self::adapters() as $label => $adapter) {
            $fields[$label] = $adapter->clickField()->field;
        }

        self::assertSame(array_values($fields), array_values(array_unique($fields)), 'two adapters name one field');
    }

    /**
     * The field an adapter names is one it reads: each word of it that is not
     * plain English is a string in the adapter's own source.
     */
    public function testEachAdapterNamesAFieldItReads(): void
    {
        foreach (self::adapters() as $label => $adapter) {
            $file = (new \ReflectionClass($adapter))->getFileName();
            self::assertIsString($file);
            $strings = array_map(
                static fn (array $literal): string => trim($literal[1], '\'"'),
                self::literals($file)
            );
            $words = preg_split('/[^A-Za-z0-9_]+/', $adapter->clickField()->field, -1, PREG_SPLIT_NO_EMPTY);
            self::assertIsArray($words);
            $read = 0;
            foreach ($words as $word) {
                if (in_array(strtolower($word), self::PLAIN_WORDS, true)) {
                    continue;
                }
                $read++;
                self::assertContains($word, $strings, $label . '\'s click field names "' . $word . '", which '
                    . basename($file) . ' does not read');
            }
            self::assertGreaterThan(0, $read, $label . '\'s click field names a field');
        }
    }

    public function testTheSharedFilesWordNoProvidersField(): void
    {
        $names = [];
        foreach (self::adapters() as $label => $adapter) {
            foreach (self::names($adapter->clickField()) as $name) {
                $names[$name] = $label;
            }
        }
        $scanned = 0;
        $found = [];
        foreach (glob(SourceScan::repoRoot() . '/' . self::SHARED . '/*.php') ?: [] as $path) {
            if (array_key_exists(basename($path), self::SHARED_EXEMPT)) {
                continue;
            }
            $scanned++;
            foreach (self::literals($path) as [$line, $text]) {
                foreach ($names as $name => $label) {
                    if (self::namesIn($text, $name)) {
                        $found[] = basename($path) . ':' . $line . ' names "' . $name . '" (' . $label . '\'s)';
                    }
                }
            }
        }

        self::assertGreaterThanOrEqual(10, $scanned, 'the shared files were read');
        self::assertSame([], $found, 'a shared answer that names a provider\'s field is that provider\'s words'
            . ' given to every provider: word it from $provider->clickField()');
    }

    public function testOnlyTheProcessorAnswersAboutTheClick(): void
    {
        $files = [];
        foreach (SourceScan::phpFiles() as $relative => $source) {
            if (str_starts_with($relative, self::SHARED . '/')) {
                $files[$relative] = $source;
            }
        }
        foreach (array_keys(self::implementations()) as $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            self::assertIsString($file);
            $relative = substr($file, strlen(SourceScan::repoRoot()) + 1);
            $files[$relative] = SourceScan::phpFiles()[$relative] ?? self::fail('not in the tree: ' . $relative);
        }
        self::assertArrayHasKey('202-config/PaymentNotification/Processor.php', $files);

        $found = [];
        foreach ($files as $relative => $source) {
            if (array_key_exists($relative, self::OUTCOME_HOMES)) {
                continue;
            }
            $sites = self::clickOutcomeSites($source);
            $allowed = self::RAISES_ITS_OWN[$relative] ?? [];
            foreach ($sites as $outcome => $lines) {
                if (array_key_exists($outcome, $allowed) && count($lines) === 1) {
                    continue;
                }
                $found[] = $relative . ':' . implode(',', $lines) . ' ' . $outcome;
            }
            foreach (array_keys($allowed) as $outcome) {
                self::assertArrayHasKey($outcome, $sites, $relative . ' is listed for a ' . $outcome . ' it no longer'
                    . ' raises: take it off the list');
            }
        }

        self::assertSame([], $found, 'an adapter that answers no_click or unknown_click itself words it apart from'
            . ' the Processor\'s answer: return the Notification with no click (or let the Processor find none), or'
            . ' list the site here with what its answer carries that the shared one cannot');
    }

    /**
     * Where a source names a click outcome: the case's name, its value in a
     * string, or a lookup of OutcomeKind by value, which cannot be read and
     * counts as both.
     *
     * @return array<string, list<int>> outcome => lines
     */
    private static function clickOutcomeSites(string $source): array
    {
        $sites = [];
        $tokens = self::code($source);
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }
            [$id, $text, $line] = $token;
            if ($id === T_STRING && in_array($text, ['NO_CLICK', 'UNKNOWN_CLICK'], true)) {
                $sites[$text][] = $line;
            } elseif ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
                foreach (['no_click' => 'NO_CLICK', 'unknown_click' => 'UNKNOWN_CLICK'] as $value => $case) {
                    if (stripos($text, $value) !== false) {
                        $sites[$case][] = $line;
                    }
                }
            } elseif ($id === T_STRING && in_array(strtolower($text), ['from', 'tryfrom', 'cases'], true)) {
                $before = $tokens[$i - 1] ?? null;
                $class = $tokens[$i - 2] ?? null;
                $lookup = is_array($before) && $before[0] === T_DOUBLE_COLON && is_array($class);
                if ($lookup && str_ends_with(strtolower($class[1]), 'outcomekind')) {
                    $sites['NO_CLICK'][] = $line;
                    $sites['UNKNOWN_CLICK'][] = $line;
                }
            }
        }

        return $sites;
    }

    /**
     * Every class in the tree that implements ProviderAdapter, or extends one
     * that does, by its full name => file. An interface that extends it, or
     * an anonymous class that implements it, cannot be listed and fails here.
     *
     * @return array<string, string>
     */
    private static function implementations(): array
    {
        $parents = [];
        $files = [];
        $direct = [];
        foreach (SourceScan::phpFiles() as $relative => $source) {
            $tokens = self::code($source);
            $namespace = '';
            $aliases = self::aliases($tokens);
            foreach ($tokens as $i => $token) {
                if (!is_array($token)) {
                    continue;
                }
                if ($token[0] === T_NAMESPACE && is_array($tokens[$i + 1] ?? null)) {
                    $namespace = $tokens[$i + 1][1];
                    continue;
                }
                if (!in_array($token[0], [T_CLASS, T_INTERFACE, T_ENUM], true)) {
                    continue;
                }
                $previous = $tokens[$i - 1] ?? null;
                if ($token[0] === T_CLASS && is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
                    continue;
                }
                $name = $tokens[$i + 1] ?? null;
                $anonymous = !is_array($name) || $name[0] !== T_STRING;
                $full = $anonymous ? null : ltrim($namespace . '\\' . $name[1], '\\');
                $extends = [];
                $implements = [];
                $list = null;
                for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j] !== '{'; $j++) {
                    $t = $tokens[$j];
                    if (is_array($t) && $t[0] === T_EXTENDS) {
                        $list = 'extends';
                    } elseif (is_array($t) && $t[0] === T_IMPLEMENTS) {
                        $list = 'implements';
                    } elseif ($list !== null && is_array($t) && in_array($t[0], self::NAMES, true)) {
                        $short = strtolower(substr((string) strrchr('\\' . $t[1], '\\'), 1));
                        $short = $aliases[$short] ?? $short;
                        if ($list === 'extends') {
                            $extends[] = $short;
                        } else {
                            $implements[] = $short;
                        }
                    }
                }
                $names = [...$extends, ...$implements];
                if ($token[0] === T_INTERFACE) {
                    self::assertNotContains('provideradapter', $names, $relative . ' declares an interface that'
                        . ' extends ProviderAdapter: this test reads only the classes that implement it');
                    continue;
                }
                if (in_array('provideradapter', $implements, true)) {
                    self::assertNotNull($full, $relative . ' has an anonymous ProviderAdapter, which cannot be listed');
                    $direct[(string) $full] = true;
                }
                if ($full !== null) {
                    $parents[$full] = $extends;
                    $files[$full] = $relative;
                }
            }
        }

        $found = $direct;
        do {
            $before = count($found);
            $shorts = array_map(
                static fn (string $class): string => strtolower(substr((string) strrchr('\\' . $class, '\\'), 1)),
                array_keys($found)
            );
            foreach ($parents as $class => $extends) {
                if (array_intersect($extends, $shorts) !== []) {
                    $found[$class] = true;
                }
            }
        } while (count($found) > $before);

        $out = [];
        foreach (array_keys($found) as $class) {
            $out[$class] = $files[$class];
        }

        return $out;
    }

    /**
     * A file's imports under another name (`use …\\ProviderAdapter as Port;`,
     * group imports included), alias => the short name it stands for, both
     * lowercase as PHP compares them.
     *
     * @param list<mixed> $tokens without whitespace and comments
     * @return array<string, string>
     */
    private static function aliases(array $tokens): array
    {
        $aliases = [];
        $inUse = false;
        foreach ($tokens as $i => $t) {
            if (is_array($t) && $t[0] === T_USE) {
                $inUse = true;
            } elseif ($t === ';' || $t === '(') {
                $inUse = false;
            } elseif ($inUse && is_array($t) && $t[0] === T_AS) {
                $name = $tokens[$i - 1] ?? null;
                $alias = $tokens[$i + 1] ?? null;
                if (is_array($name) && is_array($alias) && $alias[0] === T_STRING) {
                    $aliases[strtolower($alias[1])] = strtolower(substr((string) strrchr('\\' . $name[1], '\\'), 1));
                }
            }
        }

        return $aliases;
    }

    /**
     * Every string literal of a file, with its line.
     *
     * @return list<array{int, string}>
     */
    private static function literals(string $path): array
    {
        $source = file_get_contents($path);
        self::assertIsString($source, $path);
        $out = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], self::STRINGS, true)) {
                $out[] = [$token[2], $token[1]];
            }
        }

        return $out;
    }

    /**
     * A source's tokens without whitespace and comments.
     *
     * @return list<mixed>
     */
    private static function code(string $source): array
    {
        $layout = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

        return array_values(array_filter(
            token_get_all($source),
            static fn (mixed $t): bool => !is_array($t) || !in_array($t[0], $layout, true)
        ));
    }
}
