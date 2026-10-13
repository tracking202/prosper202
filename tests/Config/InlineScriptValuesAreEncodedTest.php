<?php

declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * A value a page echoes into an inline <script> goes through json_encode()
 * (or is a number). The footer wrote the account's dash email between single
 * quotes as it was stored, so an address with a ' (valid in a local part)
 * ended the string and broke the script, and one carrying "</script>" ended
 * the element. What is echoed otherwise is listed here with why it cannot
 * carry a quote; the list only shrinks.
 *
 * The scan reads `<?php echo …; ?>` and `<?= … ?>` between a literal <script>
 * and </script>. A script a PHP string assembles is not seen.
 */
final class InlineScriptValuesAreEncodedTest extends TestCase
{
    private const OFFSET = 'an integer: `max(0, (int) …)` or `(int) floor(…)` where it is set';
    private const SELF_URL = 'the install\'s directory under the document root (get_absolute_url()) and a fixed path';
    private const INSTALL_PATH = 'the install\'s directory under the document root, which the operator chose';

    /** @var array<string, array<string, string>> file => echoed expression => why it needs no encoding */
    private const LISTED = [
        '202-account/api-integrations.php' => [
            '$dniProcessingJson' => 'json_encode()d with the JSON_HEX_* flags where it is set',
        ],
        '202-config/template.php' => [
            '$readsDatabase ? p202CronBeaconStatement() : \'/* no cron ping */\'' => 'p202CronBeaconStatement()'
                . ' json_encode()s its URL with the JSON_HEX_* flags; the other branch is a fixed comment',
            '$readsDatabase ? p202DashEmailJson() : \'""\'' => 'p202DashEmailJson() json_encode()s the address with'
                . ' the JSON_HEX_* flags; the other branch is an empty string',
        ],
        'tracking202/ajax/ltv_companies.php' => ['$offset' => self::OFFSET, '$selfUrl' => self::SELF_URL],
        'tracking202/ajax/ltv_customer.php' => ['$selfUrl' => self::SELF_URL],
        'tracking202/ajax/ltv_merge_modal.php' => ['get_absolute_url()' => self::INSTALL_PATH],
        'tracking202/ajax/ltv_products.php' => ['$offset' => self::OFFSET, '$selfUrl' => self::SELF_URL],
        'tracking202/ajax/ltv_settings.php' => ['$selfUrl' => self::SELF_URL],
    ];

    /** @return list<array{string, int, string}> file, line, echoed expression */
    private static function echoes(string $path, string $source): array
    {
        $found = [];
        preg_match_all('~<script\b[^>]*>(.*?)</script>~is', $source, $scripts, PREG_OFFSET_CAPTURE);
        foreach ($scripts[1] as [$body, $offset]) {
            preg_match_all('~<\?(?:php\s+echo\b|=)\s*(.*?);?\s*\?>~s', $body, $echoes, PREG_OFFSET_CAPTURE);
            foreach ($echoes[1] as [$expr, $at]) {
                $line = substr_count($source, "\n", 0, $offset + $at) + 1;
                $found[] = [$path, $line, trim($expr)];
            }
        }

        return $found;
    }

    private static function encoded(string $expr): bool
    {
        return (bool) preg_match('~^(?:json_encode\(|\(int\)|\(float\)|intval\(|floatval\()~', $expr);
    }

    public function testEveryEchoIntoAScriptIsEncoded(): void
    {
        $unlisted = [];
        $seen = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (stripos($source, '<script') === false) {
                continue;
            }
            foreach (self::echoes($path, $source) as [$file, $line, $expr]) {
                if (self::encoded($expr)) {
                    continue;
                }
                if (isset(self::LISTED[$file][$expr])) {
                    $seen[$file][$expr] = true;
                    continue;
                }
                $unlisted[] = "$file:$line: $expr";
            }
        }

        self::assertSame([], $unlisted, "These echo a value into an inline <script> as it is. Write it with"
            . " json_encode(…, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), or list it with"
            . " why it cannot carry a quote:\n" . implode("\n", $unlisted));

        $stale = [];
        foreach (self::LISTED as $file => $exprs) {
            foreach (array_keys($exprs) as $expr) {
                if (!isset($seen[$file][$expr])) {
                    $stale[] = "$file: $expr";
                }
            }
        }
        self::assertSame([], $stale, "Listed but no longer echoed into a script; take them off the list:\n"
            . implode("\n", $stale));
    }

    /** @return array<string, array{string, list<string>}> */
    public static function shapes(): array
    {
        return [
            'quoted echo' => ["<script>f('<?php echo \$email; ?>');</script>", ['$email']],
            'short echo' => ['<script>var u = "<?= $u ?>";</script>', ['$u']],
            'encoded' => ['<script>f(<?php echo json_encode($email, JSON_HEX_APOS); ?>);</script>', []],
            'an integer' => ['<script>var n = <?php echo (int) $n; ?>;</script>', []],
            'outside a script' => ['<p><?php echo $name; ?></p>', []],
            'a script with attributes' => ['<script type="text/javascript">x = \'<?php echo $v ?>\'</script>', ['$v']],
        ];
    }

    /**
     * @dataProvider shapes
     * @param list<string> $expected
     */
    public function testTheScanFindsEachShape(string $source, array $expected): void
    {
        $found = array_map(static fn (array $echo): string => $echo[2], self::echoes('x.php', $source));
        $unencoded = array_values(array_filter($found, static fn (string $expr): bool => !self::encoded($expr)));

        self::assertSame($expected, $unencoded);
    }
}
