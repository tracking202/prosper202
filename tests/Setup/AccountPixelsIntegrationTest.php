<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\TrafficSourcePixels;
use Prosper202\Database\Connection;
use Prosper202\Setup\AccountPixels;
use Tests\Api\V3\SetupScratchDatabase;

/**
 * Setup › Traffic Sources' account pixels saved against a real database
 * (AccountPixels, which tracking202/setup/ppc_accounts.php saves and reads
 * them through), and read back by what fires them
 * (TrafficSourcePixels::fire()):
 *
 * - a form that lists no pixel leaves the account with none — the page
 *   built its DELETE only inside the branch for a listed pixel, so the last
 *   pixel could not be removed and kept firing;
 * - a Raw pixel's code is what the edit form shows and saves, byte for
 *   byte, however often it is saved — the page read it through
 *   stripslashes(), so each save lost a level of backslashes.
 *
 * Skips without a scratch database (SetupScratchDatabase); writes users
 * 5331 and 5332's rows.
 *
 * @group integration
 */
final class AccountPixelsIntegrationTest extends TestCase
{
    use SetupScratchDatabase;

    private const USER = 5331;
    private const OTHER = 5332;

    /** @var array<string, int> */
    private array $ids = [];

    public static function setUpBeforeClass(): void
    {
        self::connectScratchDatabase();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::restoreScratchDatabase();
            self::$db->close();
        }
        self::$db = null;
    }

    private static function cleanUp(): void
    {
        foreach ([self::USER, self::OTHER] as $u) {
            self::$db->query("DELETE p FROM 202_ppc_account_pixels p JOIN 202_ppc_accounts a ON a.ppc_account_id = p.ppc_account_id WHERE a.user_id = $u");
            self::$db->query("DELETE FROM 202_ppc_accounts WHERE user_id = $u");
            self::$db->query("DELETE FROM 202_ppc_networks WHERE user_id = $u");
        }
    }

    protected function setUp(): void
    {
        self::requireScratchDatabase();
        self::cleanUp();
        foreach ([self::USER => 'mine', self::OTHER => 'theirs'] as $user => $tag) {
            $network = self::exec("INSERT INTO 202_ppc_networks SET user_id = $user, ppc_network_name = 'source $tag', ppc_network_time = 0");
            $this->ids[$tag] = self::exec("INSERT INTO 202_ppc_accounts SET user_id = $user, ppc_network_id = $network, ppc_account_name = 'account $tag', ppc_account_time = 0");
        }
    }

    private function pixels(): AccountPixels
    {
        return new AccountPixels(new Connection(self::$db));
    }

    /**
     * What the form posts after showing the account's pixels and the user
     * changing them with $edit (pixel index => new code; '' clears it).
     *
     * @param array<int, string> $edit
     * @return array<string, list<string>>
     */
    private function formPost(int $account, array $edit = []): array
    {
        $post = ['pixel_type_id' => [], 'pixel_code' => [], 'pixel_id' => [], 'pixel_correction_url' => []];
        $shown = $this->pixels()->forAccount($account);
        if ($shown === []) {
            // The form always shows one empty row.
            $shown = [['pixel_id' => '', 'pixel_type_id' => '', 'pixel_code' => '']];
        }
        foreach ($shown as $i => $pixel) {
            // What a browser posts back from the escaped textarea is the
            // value the page escaped, decoded.
            $post['pixel_type_id'][] = (string) $pixel['pixel_type_id'];
            $post['pixel_code'][] = $edit[$i] ?? html_entity_decode(htmlspecialchars((string) $pixel['pixel_code'], ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');
            $post['pixel_id'][] = (string) $pixel['pixel_id'];
            $post['pixel_correction_url'][] = '';
        }

        return $post;
    }

    /** @param array<string, list<string>> $post */
    private function saveForm(int $account, array $post): void
    {
        $errors = [];
        $rows = AccountPixels::fromForm($post, $errors);
        self::assertSame([], $errors);
        $this->pixels()->save($account, $rows);
    }

    private function fired(int $account): string
    {
        return TrafficSourcePixels::fire(new Connection(self::$db), $account, [], static fn (): bool => true)['markup'];
    }

    public function testClearingTheOnlyPixelRemovesIt(): void
    {
        $account = $this->ids['mine'];
        $this->saveForm($account, ['pixel_type_id' => ['1'], 'pixel_code' => ['https://img.example/p.gif'], 'pixel_id' => [''], 'pixel_correction_url' => ['']]);
        self::assertCount(1, $this->pixels()->forAccount($account));
        self::assertStringContainsString('img.example', $this->fired($account));

        // The one way the form removes its first row: clear the code.
        $this->saveForm($account, $this->formPost($account, [0 => '']));

        self::assertSame([], $this->pixels()->forAccount($account), 'the last pixel survived its removal');
        self::assertSame('', $this->fired($account), 'a removed pixel still fires');
    }

    public function testAFormThatPostsNoPixelsRemovesThemAll(): void
    {
        $account = $this->ids['mine'];
        $this->saveForm($account, [
            'pixel_type_id' => ['1', '5'],
            'pixel_code' => ['https://img.example/p.gif', '<script>a()</script>'],
            'pixel_id' => ['', ''],
        ]);
        self::assertCount(2, $this->pixels()->forAccount($account));

        $this->saveForm($account, []);

        self::assertSame([], $this->pixels()->forAccount($account));
    }

    public function testRemovingOnePixelKeepsTheOthersAndTheirIds(): void
    {
        $account = $this->ids['mine'];
        $this->saveForm($account, [
            'pixel_type_id' => ['1', '5'],
            'pixel_code' => ['https://img.example/p.gif', '<script>a()</script>'],
            'pixel_id' => ['', ''],
        ]);
        [$first, $second] = $this->pixels()->forAccount($account);

        $this->saveForm($account, $this->formPost($account, [0 => '']));

        self::assertSame([$second], $this->pixels()->forAccount($account));
        self::assertNotSame($first['pixel_id'], $second['pixel_id']);
    }

    public function testARawPixelIsTheSameBytesAfterEverySave(): void
    {
        $account = $this->ids['mine'];
        // \n and \\ inside a script, and a quote escaped with one.
        $code = "<script>var s = \"line\\nbreak\", path = \"C:\\\\dir\\\\f\", q = 'it\\'s';</script>";
        self::assertStringContainsString('\\\\', $code, 'the fixture lost its double backslash');
        $this->saveForm($account, ['pixel_type_id' => ['5'], 'pixel_code' => [$code], 'pixel_id' => ['']]);

        for ($save = 1; $save <= 3; $save++) {
            $this->saveForm($account, $this->formPost($account));
            $stored = $this->pixels()->forAccount($account);
            self::assertCount(1, $stored);
            self::assertSame($code, $stored[0]['pixel_code'], "save $save changed the code");
        }
        self::assertSame($code . "\n", $this->fired($account), 'what fires is not what was saved');
    }

    public function testAPixelIdOfAnotherAccountIsANewPixelHere(): void
    {
        $theirs = $this->ids['theirs'];
        $this->saveForm($theirs, ['pixel_type_id' => ['1'], 'pixel_code' => ['https://theirs.example/p.gif'], 'pixel_id' => ['']]);
        $theirPixel = $this->pixels()->forAccount($theirs)[0];

        $mine = $this->ids['mine'];
        $this->saveForm($mine, ['pixel_type_id' => ['1'], 'pixel_code' => ['https://mine.example/p.gif'], 'pixel_id' => [(string) $theirPixel['pixel_id']]]);

        self::assertSame([$theirPixel], $this->pixels()->forAccount($theirs), "another account's pixel was changed");
        $minePixels = $this->pixels()->forAccount($mine);
        self::assertCount(1, $minePixels);
        self::assertNotSame($theirPixel['pixel_id'], $minePixels[0]['pixel_id']);
        self::assertSame('https://mine.example/p.gif', $minePixels[0]['pixel_code']);
    }

    public function testAnUpdatedPixelSaysWhatItWas(): void
    {
        $account = $this->ids['mine'];
        $this->saveForm($account, ['pixel_type_id' => ['1'], 'pixel_code' => ['https://a.example/p.gif'], 'pixel_id' => ['']]);
        $before = $this->pixels()->forAccount($account)[0];

        $errors = [];
        $saved = $this->pixels()->save($account, AccountPixels::fromForm([
            'pixel_type_id' => ['2', '3'],
            'pixel_code' => ['https://b.example/f.html', 'https://c.example/s.js'],
            'pixel_id' => [(string) $before['pixel_id'], ''],
        ], $errors));

        self::assertSame($before['pixel_id'], $saved[0]['pixel_id']);
        self::assertSame(['pixel_type_id' => 1, 'pixel_code' => 'https://a.example/p.gif'], $saved[0]['previous']);
        self::assertNull($saved[1]['previous']);
        self::assertGreaterThan(0, $saved[1]['pixel_id']);
    }
}
