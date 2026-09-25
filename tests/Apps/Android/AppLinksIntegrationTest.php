<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use Api\V3\Apps\StoreLink;
use Api\V3\Controllers\AppLinksController;
use Api\V3\Controllers\CampaignsController;
use Api\V3\Exception\NotFoundException;
use Tests\TestCase;

/**
 * The link builder's read, GET /apps/{id}/store-link (PR 11), and that
 * what it says to apply, applied through PUT /campaigns/{id}, makes the
 * campaign ready — the same round trip the Setup page and `p202 app link
 * --apply` make.
 *
 * @group integration
 */
final class AppLinksIntegrationTest extends TestCase
{
    use AndroidDatabase;

    private function links(int $user = 1): AppLinksController
    {
        return new AppLinksController(self::$db, $user);
    }

    public function testAnAndroidCampaignIsReadyOnceItsLinkCarriesTheTokenAndItIsLinked(): void
    {
        $this->campaign(31, null);
        $r = json_decode((string) json_encode($this->links()->storeLink(5, ['campaign_id' => '31'])), true)['data'];
        self::assertSame('https://play.google.com/store/apps/details?id=com.example.summit&referrer=p202%3D[[p202_install_token]]', $r['store_link']);
        self::assertSame('[[p202_install_token]]', $r['android']['token']);
        self::assertFalse($r['campaign']['ready']);
        self::assertCount(2, $r['campaign']['needs'], 'the URL is not the store link, and the campaign is not linked');
        self::assertSame(['aff_campaign_url' => $r['store_link'], 'app_registration_id' => 5], $r['campaign']['apply']);

        (new CampaignsController(self::$db, 1))->update(31, $r['campaign']['apply']);
        $after = json_decode((string) json_encode($this->links()->storeLink(5, ['campaign_id' => '31'])), true)['data']['campaign'];
        self::assertTrue($after['ready'], json_encode($after));
        self::assertSame([], $after['apply']);
        self::assertSame(5, $after['app_registration_id']);
    }

    public function testALinkWithoutTheTokenOrLinkedElsewhereIsSaidByName(): void
    {
        $this->campaign(32, 5);
        self::fixture("UPDATE 202_aff_campaigns SET aff_campaign_url='https://play.google.com/store/apps/details?id=com.example.summit&p202=[[p202_install_token]]' WHERE aff_campaign_id=32");
        $c = json_decode((string) json_encode($this->links()->storeLink(5, ['campaign_id' => '32'])), true)['data']['campaign'];
        self::assertFalse($c['ready']);
        self::assertStringContainsString('does not carry [[p202_install_token]] in its referrer', implode(' ', $c['needs']),
            'a token at the top level of a Play URL is dropped by Play: said, not passed');
        self::assertSame(['aff_campaign_url'], array_keys($c['apply']));
    }

    public function testSomebodyElsesAppOrCampaignIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->links(2)->storeLink(5, []);
    }

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function tokenPlacements(): iterable
    {
        yield 'in the referrer, encoded' => ['https://play.google.com/store/apps/details?id=a.b&referrer=p202%3D[[p202_install_token]]', true];
        yield 'with other referrer entries' => ['https://play.google.com/store/apps/details?id=a.b&referrer=utm_source%3Dx%26p202%3D[[p202_install_token]]', true];
        yield 'at the top level' => ['https://play.google.com/store/apps/details?id=a.b&p202=[[p202_install_token]]', false];
        yield 'unencoded, so it is a top-level parameter' => ['https://play.google.com/store/apps/details?id=a.b&referrer=p202=[[p202_install_token]]&x=1', true];
        yield 'another token' => ['https://play.google.com/store/apps/details?id=a.b&referrer=p202%3D[[subid]]', false];
        yield 'no query' => ['https://play.google.com/store/apps/details', false];
    }

    /** @dataProvider tokenPlacements */
    public function testWhereTheTokenMustSit(string $url, bool $carries): void
    {
        self::assertSame($carries, StoreLink::carriesInstallToken($url));
    }
}
