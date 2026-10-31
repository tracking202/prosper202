<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;

/**
 * Every LTV page asks what /api/v3/ltv asks (CLAUDE.md #5): a read
 * access_to_campaign_data, a posted action on a CRM page Setup's permission,
 * and one on LTV Settings access_to_api_integrations. Each page's gate is
 * the block right after its `AUTH::require_user();`; the test checks it is
 * there and runs it in a child process for a Publisher (no permissions), a
 * read-only role, and roles that hold the write permission.
 */
final class LtvPagesRequirePermissionTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    /** page => the permission a posted action asks for, or null for a read-only page */
    private const PAGES = [
        'tracking202/analyze/ltv.php' => null,
        'tracking202/analyze/ltv_download.php' => null,
        'tracking202/ajax/ltv_company.php' => null,
        'tracking202/ajax/ltv_merge_search.php' => null,
        'tracking202/ajax/ltv_snapshot.php' => null,
        'tracking202/ajax/sort_ltv.php' => null,
        'tracking202/ajax/ltv_subscriptions.php' => null,
        'tracking202/ajax/ltv_companies.php' => 'access_to_setup_section',
        'tracking202/ajax/ltv_customer.php' => 'access_to_setup_section',
        'tracking202/ajax/ltv_products.php' => 'access_to_setup_section',
        'tracking202/ajax/ltv_settings.php' => 'access_to_api_integrations',
    ];

    public function testEveryLtvEntryPageIsListed(): void
    {
        // By name and by content: a page that reads LTV's repositories or
        // helpers is LTV's whatever it is called (sort_ltv.php was missed by name).
        $found = [];
        $files = new \RecursiveIteratorIterator(\Tests\Support\SourceScan::tree(self::ROOT . 'tracking202'));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            $isLtv = str_contains($file->getFilename(), 'ltv')
                || str_contains($src, 'Prosper202\\Ltv\\')
                || str_contains($src, 'ltv_helpers.php');
            if ($isLtv && preg_match('/^AUTH::require_user\(\);$/m', $src) === 1) {
                $found[] = substr($file->getPathname(), strlen(self::ROOT));
            }
        }
        sort($found);
        $listed = array_keys(self::PAGES);
        sort($listed);
        self::assertSame($listed, $found, 'every LTV page that signs a user in is held to a permission here');
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function pages(): iterable
    {
        foreach (self::PAGES as $page => $write) {
            yield $page => [$page, $write];
        }
    }

    /** @dataProvider pages */
    public function testThePageRefusesARoleWithoutThePermission(string $page, ?string $write): void
    {
        $gate = self::gate($page);
        $read = 'access_to_campaign_data';
        $refusedRead = "This account's role does not have the '$read' permission.";

        // A Publisher / Campaign viewer holds no permissions: refused on reads and writes.
        self::assertSame($refusedRead, self::runGate($gate, [], []), "$page refuses a read without $read");
        self::assertSame($refusedRead, self::runGate($gate, [], ['action' => 'x']), "$page refuses a write without $read");
        // A role that may read reads.
        self::assertSame('passed', self::runGate($gate, [$read], []), "$page lets a role with $read read");

        if ($write === null) {
            self::assertSame('passed', self::runGate($gate, [$read], ['action' => 'x']), "$page has no posted action to gate");
            return;
        }
        self::assertSame("This account's role does not have the '$write' permission.", self::runGate($gate, [$read], ['action' => 'x']), "$page refuses a write without $write");
        self::assertSame("This account's role does not have the '$write' permission.", self::runGate($gate, [$read], ['action' => ['x']]), "$page refuses a non-string action too");
        self::assertSame('passed', self::runGate($gate, [$read, $write], ['action' => 'x']), "$page lets a role with $write write");
    }

    /** The statements from `AUTH::require_user();` to the first blank line. */
    private static function gate(string $page): string
    {
        $src = (string) file_get_contents(self::ROOT . $page);
        self::assertSame(1, preg_match_all('/^AUTH::require_user\(\);\n((?:.+\n)+?)\n/m', $src, $m), "$page has one top-level AUTH::require_user(); followed by its gate");
        $gate = $m[1][0];
        self::assertStringStartsWith("AUTH::require_permissions('access_to_campaign_data');", $gate, "$page asks for access_to_campaign_data right after signing in");

        return implode("\n", array_filter(explode("\n", $gate), static fn (string $l): bool => !str_starts_with($l, 'AUTH::set_timezone')));
    }

    /** @param list<string> $held @param array<string, mixed> $post */
    private static function runGate(string $gate, array $held, array $post): string
    {
        $auth = realpath(self::ROOT . '202-config/functions-auth.php');
        $script = '<?php class User { public function __construct(private array $p) {} public function hasPermission($x) { return in_array($x, $this->p, true); } }'
            . ' require ' . var_export($auth, true) . ';'
            . ' $userObj = new User(' . var_export($held, true) . ');'
            . ' $_POST = ' . var_export($post, true) . ';'
            . "\n" . $gate . "\necho 'passed';";
        $file = tempnam(sys_get_temp_dir(), 'p202ltv');
        file_put_contents($file, $script);
        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
        unlink($file);

        return trim($out);
    }
}
