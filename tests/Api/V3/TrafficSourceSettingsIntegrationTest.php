<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\PpcAccountPixelsController;
use Api\V3\Controllers\PpcNetworkVariablesController;
use Api\V3\Controllers\TrackersController;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\TrafficSourcePixels;
use Prosper202\Database\Connection;

/**
 * A traffic source's custom variables (/ppc-networks/{id}/variables) and an
 * account's pixels (/ppc-accounts/{id}/pixels) against a real database, and
 * through the code that reads them: a variable the API writes is the one
 * the tracking link carries (TrackersController::getTrackingUrl()), and a
 * pixel it writes is the one a conversion fires
 * (TrafficSourcePixels::fire()). Testing the controllers alone would prove
 * the rows, not that the readers see them (CLAUDE.md #9).
 *
 * The Setup page's rules hold: only the caller's live source or account,
 * a variable or pixel only within its own, every field of a variable
 * filled in, a removed variable retired and a removed pixel erased with its
 * correction URL, a correction URL on a postback pixel only.
 *
 * Skips without a scratch database (SetupScratchDatabase); writes users
 * 5311 and 5312's rows.
 *
 * @group integration
 */
final class TrafficSourceSettingsIntegrationTest extends TestCase
{
    use SetupScratchDatabase;

    private const USER = 5311;
    private const OTHER = 5312;

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
            self::$db->query("DELETE v FROM 202_ppc_network_variables v JOIN 202_ppc_networks n ON n.ppc_network_id = v.ppc_network_id WHERE n.user_id = $u");
            self::$db->query("DELETE p FROM 202_ppc_account_pixels p JOIN 202_ppc_accounts a ON a.ppc_account_id = p.ppc_account_id WHERE a.user_id = $u");
            self::$db->query("DELETE FROM 202_notification_correction_urls WHERE user_id = $u");
            foreach (['202_trackers', '202_ppc_accounts', '202_ppc_networks', '202_aff_campaigns', '202_aff_networks'] as $table) {
                self::$db->query("DELETE FROM $table WHERE user_id = $u");
            }
        }
    }

    protected function setUp(): void
    {
        self::requireScratchDatabase();
        self::cleanUp();
        self::setTrackingDomain('track.example.com');
        foreach ([self::USER => 'mine', self::OTHER => 'theirs'] as $user => $tag) {
            $this->ids["network_$tag"] = self::exec("INSERT INTO 202_ppc_networks SET user_id = $user, ppc_network_name = 'source $tag', ppc_network_time = 0");
            $this->ids["account_$tag"] = self::exec("INSERT INTO 202_ppc_accounts SET user_id = $user, ppc_network_id = {$this->ids["network_$tag"]}, ppc_account_name = 'account $tag', ppc_account_time = 0");
        }
    }

    private function vars(int $user = self::USER): PpcNetworkVariablesController
    {
        return new PpcNetworkVariablesController(self::requireScratchDatabase(), $user);
    }

    private function pixels(int $user = self::USER): PpcAccountPixelsController
    {
        return new PpcAccountPixelsController(self::requireScratchDatabase(), $user);
    }

    /** @return array<string, string> */
    private function refusal(callable $call): array
    {
        try {
            $call();
        } catch (ValidationException $e) {
            return $e->getFieldErrors();
        }
        self::fail('the request was answered, not refused');
    }

    private function notFound(callable $call, string $message): void
    {
        try {
            $call();
            self::fail("answered; expected: $message");
        } catch (NotFoundException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    public function testAVariableIsWrittenReadAndCarriedByTheTrackingLink(): void
    {
        $network = $this->ids['network_mine'];
        self::assertSame(['data' => []], $this->vars()->list($network));
        $created = $this->vars()->create($network, ['name' => '  Ad id ', 'parameter' => 'adid', 'placeholder' => '{ad_id}'])['data'];
        self::assertSame(['ppc_network_id' => $network, 'name' => 'Ad id', 'parameter' => 'adid', 'placeholder' => '{ad_id}'], array_diff_key($created, ['ppc_variable_id' => 1]));
        $keyword = $this->vars()->create($network, ['name' => 'Keyword', 'parameter' => 't202kw', 'placeholder' => '{keyword}'])['data'];
        self::assertSame([$created, $keyword], $this->vars()->list($network)['data']);

        $tracker = self::exec('INSERT INTO 202_trackers SET user_id = ' . self::USER . ", aff_campaign_id = 1, text_ad_id = 0, landing_page_id = 0, click_cloaking = -1, ppc_account_id = {$this->ids['account_mine']}, tracker_id_public = 5311001, tracker_time = 0");
        $link = fn (): string => (new TrackersController(self::$db, self::USER))->getTrackingUrl($tracker, [], self::server())['data']['direct_url'];
        self::assertSame('https://track.example.com/tracking202/redirect/dl.php?t202id=5311001&adid={ad_id}&t202kw={keyword}', $link());

        $updated = $this->vars()->update($network, $created['ppc_variable_id'], ['placeholder' => '{{ad.id}}'])['data'];
        self::assertSame('{{ad.id}}', $updated['placeholder']);
        self::assertSame('Ad id', $updated['name'], 'an update changes only what it names');
        self::assertStringContainsString('&adid={{ad.id}}&', $link());

        $preview = $this->vars()->deletePreview($network, $created['ppc_variable_id'])['data'];
        self::assertSame(['soft', $updated], [$preview['mode'], $preview['record']]);
        $this->vars()->delete($network, $created['ppc_variable_id']);
        self::assertSame('1', (string) self::row("SELECT deleted FROM 202_ppc_network_variables WHERE ppc_variable_id = {$created['ppc_variable_id']}")['deleted'], 'retired, as the dialog retires a row, not erased');
        self::assertSame([$keyword], $this->vars()->list($network)['data']);
        self::assertSame('https://track.example.com/tracking202/redirect/dl.php?t202id=5311001&t202kw={keyword}', $link());
        $this->notFound(fn () => $this->vars()->delete($network, $created['ppc_variable_id']), "Variable {$created['ppc_variable_id']} not found on traffic source $network");
    }

    public function testOnlyTheCallersLiveSourceAndItsOwnVariablesAreReached(): void
    {
        $mine = $this->ids['network_mine'];
        $theirs = $this->ids['network_theirs'];
        $theirVariable = $this->vars(self::OTHER)->create($theirs, ['name' => 'T', 'parameter' => 't', 'placeholder' => '{t}'])['data']['ppc_variable_id'];
        $message = "Traffic source $theirs not found";
        $this->notFound(fn () => $this->vars()->list($theirs), $message);
        $this->notFound(fn () => $this->vars()->create($theirs, ['name' => 'x', 'parameter' => 'x', 'placeholder' => 'x']), $message);
        $this->notFound(fn () => $this->vars()->update($theirs, $theirVariable, ['name' => 'x']), $message);
        $this->notFound(fn () => $this->vars()->delete($theirs, $theirVariable), $message);
        $this->notFound(fn () => $this->vars()->deletePreview($theirs, $theirVariable), $message);
        // Another source's variable through my own source.
        $this->notFound(fn () => $this->vars()->update($mine, $theirVariable, ['name' => 'x']), "Variable $theirVariable not found on traffic source $mine");
        $this->notFound(fn () => $this->vars()->delete($mine, $theirVariable), "Variable $theirVariable not found on traffic source $mine");
        self::assertSame('T', (string) self::row("SELECT name FROM 202_ppc_network_variables WHERE ppc_variable_id = $theirVariable")['name'], 'their variable is untouched');
        self::assertSame('0', (string) self::row("SELECT deleted FROM 202_ppc_network_variables WHERE ppc_variable_id = $theirVariable")['deleted']);
        // A removed source.
        self::exec("UPDATE 202_ppc_networks SET ppc_network_deleted = 1 WHERE ppc_network_id = $mine");
        $this->notFound(fn () => $this->vars()->list($mine), "Traffic source $mine not found");
    }

    public function testAVariableIsHeldToTheDialogsRulesAndToWhatALinkCanCarry(): void
    {
        $network = $this->ids['network_mine'];
        $create = fn (array $payload): array => $this->refusal(fn () => $this->vars()->create($network, $payload));
        self::assertSame(['name', 'parameter', 'placeholder'], array_keys($create([])));
        self::assertSame(['name' => 'Must not be blank'], $create(['name' => '  ', 'parameter' => 'p', 'placeholder' => 'x']));
        self::assertSame(['placeholder' => 'Must be text'], $create(['name' => 'n', 'parameter' => 'p', 'placeholder' => 5]));
        self::assertArrayHasKey('placholder', $create(['name' => 'n', 'parameter' => 'p', 'placeholder' => 'x', 'placholder' => 'y']));
        foreach (['a b', 'a&b', 'a#b', 'a?b', "a\nb", 'a=b', 't202id', 'T202ID', str_repeat('p', 256)] as $parameter) {
            self::assertArrayHasKey('parameter', $create(['name' => 'n', 'parameter' => $parameter, 'placeholder' => 'x']), json_encode($parameter) . ' as a parameter');
        }
        foreach (['{a b}', '{a}&b=1', '#s1#', "\t"] as $placeholder) {
            self::assertArrayHasKey('placeholder', $create(['name' => 'n', 'parameter' => 'p', 'placeholder' => $placeholder]), json_encode($placeholder) . ' as a placeholder');
        }
        self::assertArrayHasKey('name', $create(['name' => str_repeat('é', 256), 'parameter' => 'p', 'placeholder' => 'x']));
        self::assertSame(str_repeat('é', 255), $this->vars()->create($network, ['name' => str_repeat('é', 255), 'parameter' => 'p', 'placeholder' => '[[kw]]'])['data']['name'], '255 characters fit the column');

        $id = $this->vars()->list($network)['data'][0]['ppc_variable_id'];
        self::assertArrayHasKey('name', $this->refusal(fn () => $this->vars()->update($network, $id, [])));
        self::assertArrayHasKey('placeholder', $this->refusal(fn () => $this->vars()->update($network, $id, ['placeholder' => ''])));
        self::assertSame(0, (int) self::row("SELECT COUNT(*) AS n FROM 202_ppc_network_variables WHERE ppc_network_id = $network AND parameter <> 'p'")['n'], 'nothing refused was written');
    }

    public function testAPixelIsWrittenAndIsTheOneAConversionFires(): void
    {
        $account = $this->ids['account_mine'];
        self::assertSame(['data' => []], $this->pixels()->list($account));
        $image = $this->pixels()->create($account, ['pixel_type_id' => 1, 'pixel_code' => "  https://img.example/px.gif?c=[[subid]]\n"])['data'];
        self::assertSame([1, 'Image', 'https://img.example/px.gif?c=[[subid]]', ''], [$image['pixel_type_id'], $image['pixel_type'], $image['pixel_code'], $image['correction_url']]);
        $postback = $this->pixels()->create($account, [
            'pixel_type_id' => '4',
            'pixel_code' => 'https://net.example/pb?c=[[subid]]&tx=[[transactionid]]',
            'correction_url' => 'https://net.example/fix?tx=[[transactionid]]&v=[[p202_goal_value]]',
        ])['data'];
        self::assertSame('https://net.example/fix?tx=[[transactionid]]&v=[[p202_goal_value]]', $postback['correction_url']);
        self::assertSame($postback['correction_url'], (string) self::row("SELECT correction_url FROM 202_notification_correction_urls WHERE pixel_id = {$postback['pixel_id']} AND user_id = " . self::USER)['correction_url']);
        self::assertSame([$image, $postback], $this->pixels()->list($account)['data']);

        $fetched = [];
        $fired = TrafficSourcePixels::fire(new Connection(self::$db), $account, ['subid' => '77', 'transactionid' => 'T1'], static function (string $url) use (&$fetched): bool {
            $fetched[] = $url;
            return true;
        });
        self::assertSame(['https://net.example/pb?c=77&tx=T1'], $fetched, 'the postback the API wrote is the one sent');
        self::assertStringContainsString("<img src='https://img.example/px.gif?c=77'", $fired['markup'], 'the image the API wrote is the one rendered');

        // A postback that becomes an image keeps no correction URL.
        $changed = $this->pixels()->update($account, $postback['pixel_id'], ['pixel_type_id' => 1, 'pixel_code' => 'https://img.example/two.gif'])['data'];
        self::assertSame([1, ''], [$changed['pixel_type_id'], $changed['correction_url']]);
        self::assertNull(self::row("SELECT correction_url FROM 202_notification_correction_urls WHERE pixel_id = {$postback['pixel_id']}"));

        $preview = $this->pixels()->deletePreview($account, $image['pixel_id'])['data'];
        self::assertSame(['hard', $image], [$preview['mode'], $preview['record']]);
        $this->pixels()->delete($account, $image['pixel_id']);
        self::assertNull(self::row("SELECT pixel_id FROM 202_ppc_account_pixels WHERE pixel_id = {$image['pixel_id']}"), 'erased, as the form erases a pixel it no longer lists');
        self::assertSame([$changed], $this->pixels()->list($account)['data']);
    }

    public function testRemovingAPostbackRemovesItsCorrectionUrl(): void
    {
        $account = $this->ids['account_mine'];
        $pixel = $this->pixels()->create($account, ['pixel_type_id' => 4, 'pixel_code' => 'https://a.example/1 https://b.example/2', 'correction_url' => 'https://a.example/fix https://b.example/fix'])['data'];
        self::assertSame(1, $this->pixels()->deletePreview($account, $pixel['pixel_id'])['data']['cascade'][0]['count']);
        $this->pixels()->delete($account, $pixel['pixel_id']);
        self::assertNull(self::row("SELECT pixel_id FROM 202_notification_correction_urls WHERE pixel_id = {$pixel['pixel_id']}"), 'so a later pixel with the id cannot inherit it');
    }

    public function testAPixelIsHeldToTheFormsRules(): void
    {
        $account = $this->ids['account_mine'];
        $create = fn (array $payload): array => $this->refusal(fn () => $this->pixels()->create($account, $payload));
        self::assertSame(['pixel_type_id', 'pixel_code'], array_keys($create([])));
        foreach ([0, 7, '1.0', '01', ' 1', 1.0, true, null] as $type) {
            self::assertArrayHasKey('pixel_type_id', $create(['pixel_type_id' => $type, 'pixel_code' => 'https://x.example/']), json_encode($type) . ' is not a pixel type');
        }
        self::assertArrayHasKey('pixel_code', $create(['pixel_type_id' => 1, 'pixel_code' => "  \n "]));
        self::assertArrayHasKey('pixel_code', $create(['pixel_type_id' => 1, 'pixel_code' => ['https://x.example/']]));
        self::assertArrayHasKey('pixel_code', $create(['pixel_type_id' => 4, 'pixel_code' => 'net.example/pb?c=[[subid]]']), 'a postback the sender would never call');
        self::assertSame(
            ['correction_url' => 'A correction URL goes on a server-to-server (Postback URL) pixel only: the other pixel types are fired by a browser, which is not there when a correction is sent.'],
            $create(['pixel_type_id' => 1, 'pixel_code' => 'https://x.example/', 'correction_url' => 'https://x.example/fix'])
        );
        self::assertArrayHasKey('correction_url', $create(['pixel_type_id' => 4, 'pixel_code' => 'https://x.example/', 'correction_url' => 'https://a.example/fix https://b.example/fix']), 'more correction URLs than the code has URLs');
        self::assertArrayHasKey('correction_url', $create(['pixel_type_id' => 4, 'pixel_code' => 'https://x.example/', 'correction_url' => 'mailto:x@example.com']));
        self::assertArrayHasKey('pixel_id', $create(['pixel_type_id' => 1, 'pixel_code' => 'https://x.example/', 'pixel_id' => 9]), 'an id is not chosen by the caller');
        self::assertSame(['data' => []], $this->pixels()->list($account), 'nothing refused was written');

        $raw = $this->pixels()->create($account, ['pixel_type_id' => 5, 'pixel_code' => '<script>fire("[[subid]]")</script>'])['data'];
        self::assertSame('<script>fire("[[subid]]")</script>', $raw['pixel_code'], 'Raw markup is stored as given');
        self::assertArrayHasKey('pixel_code', $this->refusal(fn () => $this->pixels()->update($account, $raw['pixel_id'], ['pixel_type_id' => 4])), 'a change of type is checked against the code it keeps');
        $postback = $this->pixels()->create($account, ['pixel_type_id' => 4, 'pixel_code' => 'https://a.example/1 https://b.example/2', 'correction_url' => 'https://a.example/fix https://b.example/fix'])['data'];
        self::assertArrayHasKey('correction_url', $this->refusal(fn () => $this->pixels()->update($account, $postback['pixel_id'], ['pixel_code' => 'https://a.example/1'])), 'a code that loses a URL is checked against the correction URLs it keeps');
    }

    public function testOnlyTheCallersLiveAccountAndItsOwnPixelsAreReached(): void
    {
        $mine = $this->ids['account_mine'];
        $theirs = $this->ids['account_theirs'];
        $theirPixel = $this->pixels(self::OTHER)->create($theirs, ['pixel_type_id' => 1, 'pixel_code' => 'https://their.example/'])['data']['pixel_id'];
        $message = "Traffic source account $theirs not found";
        $this->notFound(fn () => $this->pixels()->list($theirs), $message);
        $this->notFound(fn () => $this->pixels()->create($theirs, ['pixel_type_id' => 1, 'pixel_code' => 'https://x.example/']), $message);
        $this->notFound(fn () => $this->pixels()->update($theirs, $theirPixel, ['pixel_code' => 'https://x.example/']), $message);
        $this->notFound(fn () => $this->pixels()->delete($theirs, $theirPixel), $message);
        $this->notFound(fn () => $this->pixels()->update($mine, $theirPixel, ['pixel_code' => 'https://x.example/']), "Pixel $theirPixel not found on traffic source account $mine");
        $this->notFound(fn () => $this->pixels()->delete($mine, $theirPixel), "Pixel $theirPixel not found on traffic source account $mine");
        self::assertSame('https://their.example/', (string) self::row("SELECT pixel_code FROM 202_ppc_account_pixels WHERE pixel_id = $theirPixel")['pixel_code']);

        // An account of mine filed under another account's source: the form
        // refuses to save one ("another user's traffic source").
        $borrowed = self::exec('INSERT INTO 202_ppc_accounts SET user_id = ' . self::USER . ", ppc_network_id = {$this->ids['network_theirs']}, ppc_account_name = 'borrowed', ppc_account_time = 0");
        $this->notFound(fn () => $this->pixels()->list($borrowed), "Traffic source account $borrowed not found");
        self::exec("UPDATE 202_ppc_accounts SET ppc_account_deleted = 1 WHERE ppc_account_id = $mine");
        $this->notFound(fn () => $this->pixels()->list($mine), "Traffic source account $mine not found");
    }
}
